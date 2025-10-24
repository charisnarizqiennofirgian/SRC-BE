<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class StockAdjustmentController extends Controller
{
    /**
     * Menyimpan data penyesuaian stok baru (VERSI FINAL & BERSIH).
     */
  public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|integer|exists:items,id',
            'type' => 'required|string|in:Stok Masuk,Stok Keluar',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:1000',
        ], [
            'quantity.min' => 'Kuantitas harus lebih besar dari 0.',
            'type.in' => 'Tipe penyesuaian tidak valid.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false, 
                'message' => 'Validasi gagal.', 
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $item = Item::lockForUpdate()->findOrFail($request->item_id);
            
            $quantity = (float) $request->quantity;
            $type = $request->type;
            $movementQuantity = ($type === 'Stok Keluar') ? -$quantity : $quantity;

            StockMovement::create([
                'item_id' => $item->id,
                'type' => $type,
                'quantity' => $movementQuantity,
                'notes' => $request->notes ?? 'Penyesuaian manual dari admin.',
            ]);

            $item->increment('stock', $movementQuantity);
            $item->refresh();

            DB::commit();
            
            return response()->json([
                'success' => true, 
                'message' => 'Penyesuaian stok berhasil disimpan.',
                'new_stock' => $item->stock 
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal melakukan penyesuaian stok: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false, 
                'message' => 'Terjadi kesalahan pada server.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}