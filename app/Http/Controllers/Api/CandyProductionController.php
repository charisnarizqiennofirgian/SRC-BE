<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CandyProductionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'                => ['required', 'date'],
            'notes'               => ['nullable', 'string'],
            'source_inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'target_item_id'      => ['required', 'integer', 'exists:items,id'],
            'qty'                 => ['required', 'integer', 'min:1'],
        ]);

        return DB::transaction(function () use ($data) {
            // 1. Ambil inventory sumber (Gudang Sanwil, item basah)
            $sourceInv = Inventory::lockForUpdate()->findOrFail($data['source_inventory_id']);

            if ($data['qty'] > $sourceInv->qty) {
                throw ValidationException::withMessages([
                    'qty' => ['Qty melebihi stok tersedia di Gudang Sanwil untuk baris inventory ini.'],
                ]);
            }

            // 2. Cari gudang Candy
            $candyWarehouse = Warehouse::where('name', 'like', '%Gudang Candy%')->first();
            if (!$candyWarehouse) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Candy tidak ditemukan di master.'],
                ]);
            }

            $fromWarehouseId = $sourceInv->warehouse_id;      // Sanwil
            $toWarehouseId   = $candyWarehouse->id;           // Candy
            $sourceItemId    = $sourceInv->item_id;           // item basah
            $targetItemId    = $data['target_item_id'];       // item kering

            // 3. Kurangi INVENTORY Sanwil (item basah)
            $sourceInv->qty -= $data['qty'];
            $sourceInv->save();

            // 4. Tambah / buat INVENTORY Candy (item kering, warisi ref_po_id & ref_product_id)
            $targetInv = Inventory::where('warehouse_id', $toWarehouseId)
                ->where('item_id', $targetItemId)
                ->where('ref_po_id', $sourceInv->ref_po_id)
                ->where('ref_product_id', $sourceInv->ref_product_id)
                ->lockForUpdate()
                ->first();

            if ($targetInv) {
                $targetInv->qty += $data['qty'];
                $targetInv->save();
            } else {
                $targetInv = Inventory::create([
                    'warehouse_id'   => $toWarehouseId,
                    'item_id'        => $targetItemId,
                    'qty'            => $data['qty'],
                    'ref_po_id'      => $sourceInv->ref_po_id,
                    'ref_product_id' => $sourceInv->ref_product_id,
                ]);
            }

            // 5. Kurangi STOCK pcs di Gudang Sanwil (item basah)
            $fromStock = Stock::where('warehouse_id', $fromWarehouseId)
                ->where('item_id', $sourceItemId)
                ->lockForUpdate()
                ->first();

            if ($fromStock) {
                if ($fromStock->quantity < $data['qty']) {
                    throw ValidationException::withMessages([
                        'qty' => ['Stok pcs di Gudang Sanwil tidak mencukupi.'],
                    ]);
                }
                $fromStock->decrement('quantity', $data['qty']);
            }

            // 6. Tambah STOCK pcs di Gudang Candy (item kering)
            $toStock = Stock::where('warehouse_id', $toWarehouseId)
                ->where('item_id', $targetItemId)
                ->lockForUpdate()
                ->first();

            if ($toStock) {
                $toStock->increment('quantity', $data['qty']);
            } else {
                Stock::create([
                    'warehouse_id' => $toWarehouseId,
                    'item_id'      => $targetItemId,
                    'quantity'     => $data['qty'],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Proses Candy berhasil disimpan.',
                'data'    => [
                    'source_inventory' => $sourceInv,
                    'target_inventory' => $targetInv,
                ],
            ], 201);
        });
    }
}
