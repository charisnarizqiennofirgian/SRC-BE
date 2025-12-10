<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ProductionLog;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProductionController extends Controller
{
    // TRANSFORMASI: SAWMILL, MOULDING, MESIN, PACKING, dll
    public function storeTransformation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'stage'           => 'required|string',     // SAWMILL / MOULDING / MESIN / PACKING / dll
            'process_type'    => 'required|string',     // bebas, misal sama dengan stage
            'date'            => 'required|date',
            'input_item_id'   => 'required|exists:items,id',
            'input_quantity'  => 'required|numeric|min:0.0001',
            'output_item_id'  => 'required|exists:items,id',
            'output_quantity' => 'required|numeric|min:0.0001',
            'notes'           => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        DB::beginTransaction();
        try {
            $inputItem  = Item::findOrFail($data['input_item_id']);
            $outputItem = Item::findOrFail($data['output_item_id']);

            // cek stok input
            if ($inputItem->stock < $data['input_quantity']) {
                throw new \Exception("Stok {$inputItem->name} tidak cukup. Dibutuhkan {$data['input_quantity']}, tersedia {$inputItem->stock}");
            }

            $userId = Auth::id();
            $refNo  = strtoupper($data['stage']) . '-' . date('YmdHis');

            // KURANGI stok input
            $oldIn = $inputItem->stock;
            $inputItem->stock -= $data['input_quantity'];
            $inputItem->save();

            StockMovement::create([
                'item_id'   => $inputItem->id,
                'type'      => "Produksi {$data['stage']} (Keluar)",
                'quantity'  => -$data['input_quantity'],
                'notes'     => $data['notes'] ?? null,
                'old_stock' => $oldIn,
                'new_stock' => $inputItem->stock,
            ]);

            // TAMBAH stok output
            $oldOut = $outputItem->stock;
            $outputItem->stock += $data['output_quantity'];
            $outputItem->save();

            StockMovement::create([
                'item_id'   => $outputItem->id,
                'type'      => "Produksi {$data['stage']} (Masuk)",
                'quantity'  => $data['output_quantity'],
                'notes'     => $data['notes'] ?? null,
                'old_stock' => $oldOut,
                'new_stock' => $outputItem->stock,
            ]);

            // LOG PRODUKSI
            ProductionLog::create([
                'date'            => $data['date'],
                'reference_number'=> $refNo,
                'process_type'    => $data['process_type'],
                'stage'           => strtoupper($data['stage']),
                'input_item_id'   => $inputItem->id,
                'input_quantity'  => $data['input_quantity'],
                'output_item_id'  => $outputItem->id,
                'output_quantity' => $data['output_quantity'],
                'notes'           => $data['notes'] ?? null,
                'user_id'         => $userId,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Transaksi produksi {$data['stage']} berhasil dicatat.",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // MUTASI: CANDY, PEMBAHANAN (pindah gudang, stok item sama)
    public function storeMutation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'stage'          => 'required|string',   // CANDY / PEMBAHANAN
            'process_type'   => 'required|string',
            'date'           => 'required|date',
            'item_id'        => 'required|exists:items,id',
            'quantity'       => 'required|numeric|min:0.0001',
            'from_location'  => 'required|string',
            'to_location'    => 'required|string',
            'notes'          => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        DB::beginTransaction();
        try {
            $item = Item::findOrFail($data['item_id']);

            if ($item->stock < $data['quantity']) {
                throw new \Exception("Stok {$item->name} tidak cukup untuk mutasi.");
            }

            $userId = Auth::id();
            $refNo  = strtoupper($data['stage']) . '-' . date('YmdHis');

            // Secara stok total tidak berubah, jadi kita TIDAK ubah item->stock di sini.
            // Kalau nanti ada tabel stok per gudang, di situ yang diupdate.

            // LOG MUTASI di production_logs (input & output item sama)
            ProductionLog::create([
                'date'            => $data['date'],
                'reference_number'=> $refNo,
                'process_type'    => $data['process_type'],
                'stage'           => strtoupper($data['stage']),
                'input_item_id'   => $item->id,
                'input_quantity'  => $data['quantity'],
                'output_item_id'  => $item->id,
                'output_quantity' => $data['quantity'],
                'notes'           => "Mutasi {$data['from_location']} → {$data['to_location']}. " . ($data['notes'] ?? ''),
                'user_id'         => $userId,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Mutasi {$data['stage']} berhasil dicatat.",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
