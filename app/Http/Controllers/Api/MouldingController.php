<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MouldingController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'po_id'               => ['required', 'integer', 'exists:production_orders,id'],
            'source_inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'input_qty'           => ['required', 'integer', 'min:1'],
            'output_item_id'      => ['required', 'integer', 'exists:items,id'],
            'output_qty'          => ['required', 'integer', 'min:1'],
        ]);

        return DB::transaction(function () use ($data) {
            $sourceInv = Inventory::lockForUpdate()->findOrFail($data['source_inventory_id']);

            if ($data['input_qty'] > $sourceInv->qty) {
                throw ValidationException::withMessages([
                    'input_qty' => ['Qty yang diambil melebihi stok tersedia di gudang sumber.'],
                ]);
            }

            $sourceInv->qty -= $data['input_qty'];
            $sourceInv->save();

            $targetWarehouseId = 5;

            $targetInv = Inventory::where('warehouse_id', $targetWarehouseId)
                ->where('item_id', $data['output_item_id'])
                ->where('ref_po_id', $data['po_id'])
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
                'message' => 'Proses Moulding berhasil disimpan.',
                'data'    => [
                    'source_inventory' => $sourceInv,
                    'target_inventory' => $targetInv,
                ],
            ], 201);
        });
    }

    public function sourceInventories(Request $request)
    {
        $pembahananWarehouseId = 4;

        $query = Inventory::where('warehouse_id', $pembahananWarehouseId)
            ->where('qty', '>', 0)
            ->with(['item', 'warehouse']);

        if ($request->filled('po_id')) {
            $query->where('ref_po_id', $request->po_id);
        }

        $inventories = $query->get(['id', 'warehouse_id', 'item_id', 'qty', 'ref_po_id']);

        $mapped = $inventories->map(function ($inv) {
            return [
                'id'            => $inv->id,
                'warehouse_id'  => $inv->warehouse_id,
                'item_id'       => $inv->item_id,
                'item_name'     => $inv->item->name ?? '',
                'warehouse'     => $inv->warehouse->name ?? '',
                'available_qty' => (int) $inv->qty,
                'ref_po_id'     => $inv->ref_po_id,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $mapped,
        ]);
    }
}
