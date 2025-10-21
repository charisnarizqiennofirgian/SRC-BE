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
            'type' => 'required|string|in:Stok Masuk,Stok Keluar,Stok Awal,Koreksi',
            'quantity' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $item = Item::findOrFail($request->item_id);
            
            $quantity = $request->quantity;
            $movementQuantity = $request->type === 'Stok Keluar' ? -$quantity : $quantity;

            // ✅ PERBAIKAN: Pastikan setiap baris di dalam array diakhiri koma.
            StockMovement::create([
                'item_id' => $item->id,
                'type' => $request->type,
                'quantity' => $movementQuantity,
                'notes' => $request->notes, // 
            ]);

            $item->stock = $item->stock + $movementQuantity;
            $item->save();

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Penyesuaian stok berhasil disimpan.'], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Gagal melakukan penyesuaian stok: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan pada server.'], 500);
        }
    }

    // Kita bisa perbaiki fungsi upload ini nanti setelah fungsi utama berjalan
    // public function upload(Request $request) { ... }
}