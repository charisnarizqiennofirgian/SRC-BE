<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OperatorMesinController extends Controller
{
    /**
     * POST /operator-mesin/produce
     * 
     * Input manual: User pilih sendiri kayu apa yang diambil & komponen apa yang jadi
     * TIDAK ADA validasi resep, TIDAK ADA auto-calculate
     */
    public function produce(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'production_order_id' => ['required', 'integer', 'exists:production_orders,id'],
            
            // Bahan baku (kayu RST yang diambil)
            'materials' => ['required', 'array', 'min:1'],
            'materials.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'materials.*.qty' => ['required', 'numeric', 'min:0.001'],
            
            // Hasil produksi (komponen yang jadi)
            'components' => ['required', 'array', 'min:1'],
            'components.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'components.*.qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $warehouseMouldingId = 5; // Gudang Moulding (Kayu RST)
        $warehouseKomponenId = 6; // Gudang Komponen

        DB::beginTransaction();

        try {
            // STEP 1: Kurangi stok bahan baku (kayu RST)
            foreach ($data['materials'] as $material) {
                $itemId = $material['item_id'];
                $qtyUsed = $material['qty'];

                $inventory = Inventory::where('warehouse_id', $warehouseMouldingId)
                    ->where('item_id', $itemId)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Stok kayu ID {$itemId} tidak ditemukan di Gudang Moulding!",
                    ], 422);
                }

                if ($inventory->qty < $qtyUsed) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Stok {$inventory->item->name} tidak cukup! (Tersedia: {$inventory->qty})",
                    ], 422);
                }

                // Kurangi stok
                $inventory->decrement('qty', $qtyUsed);
            }

            // STEP 2: Tambah stok komponen jadi
            foreach ($data['components'] as $component) {
                $itemId = $component['item_id'];
                $qtyProduced = $component['qty'];

                $inventory = Inventory::where('warehouse_id', $warehouseKomponenId)
                    ->where('item_id', $itemId)
                    ->first();

                if ($inventory) {
                    // Update stok yang sudah ada
                    $inventory->increment('qty', $qtyProduced);
                } else {
                    // Buat baris baru kalau belum ada
                    Inventory::create([
                        'warehouse_id' => $warehouseKomponenId,
                        'item_id' => $itemId,
                        'qty' => $qtyProduced,
                        'ref_po_id' => $data['production_order_id'],
                        'ref_product_id' => null,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => '✅ Hasil produksi berhasil disimpan!',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }
}