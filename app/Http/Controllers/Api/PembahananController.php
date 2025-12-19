<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PembahananController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'po_id'               => ['required', 'integer', 'exists:production_orders,id'],
            'source_inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'input_qty'           => ['required', 'integer', 'min:1'], // pcs
            'output_item_id'      => ['required', 'integer', 'exists:items,id'],
            'output_qty'          => ['required', 'integer', 'min:1'], // pcs
        ]);

        return DB::transaction(function () use ($data) {
            // 1. Ambil inventory sumber (Gudang Candy, bebas PO atau null)
            $sourceInv = Inventory::lockForUpdate()->findOrFail($data['source_inventory_id']);

            if ($data['input_qty'] > $sourceInv->qty) {
                throw ValidationException::withMessages([
                    'input_qty' => ['Qty yang diambil melebihi stok tersedia di gudang sumber.'],
                ]);
            }

            // 2. Kurangi qty sumber (credit gudang Candy)
            $sourceInv->qty -= $data['input_qty'];
            $sourceInv->save();

            // 3. Tambah / buat inventory hasil di Gudang Pembahanan (ID 4)
            $targetWarehouseId = 4; // Gudang Pembahanan (BUFFER RST)

            $targetInv = Inventory::where('warehouse_id', $targetWarehouseId)
                ->where('item_id', $data['output_item_id'])
                ->where('ref_po_id', $data['po_id'])          // CLAIM ke PO target
                ->where('ref_product_id', $sourceInv->ref_product_id)
                ->lockForUpdate()
                ->first();

            if ($targetInv) {
                $targetInv->qty += $data['output_qty'];
                $targetInv->save();
            } else {
                $targetInv = Inventory::create([
                    'warehouse_id'   => $targetWarehouseId,
                    'item_id'        => $data['output_item_id'],
                    'qty'            => $data['output_qty'],
                    'ref_po_id'      => $data['po_id'],
                    'ref_product_id' => $sourceInv->ref_product_id,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Proses Pembahanan berhasil disimpan.',
                'data'    => [
                    'source_inventory' => $sourceInv,
                    'target_inventory' => $targetInv,
                ],
            ], 201);
        });
    }

    public function sourceInventories(Request $request)
    {
        $candyWarehouseId = 3; // Gudang Candy (RST Kering) sebagai sumber

        $inventories = Inventory::where('warehouse_id', $candyWarehouseId)
            ->where('qty', '>', 0) // hanya stok tersedia
            ->with(['item', 'warehouse'])
            ->get(['id', 'warehouse_id', 'item_id', 'qty']); // kirim qty pcs saja dulu [web:48]

        return response()->json([
            'success' => true,
            'data'    => $inventories,
        ]);
    }
}
