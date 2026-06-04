<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrototypeController extends Controller
{
    public function getAvailableProductionOrders()
    {
        $pos = ProductionOrder::where('status', '!=', 'completed')
            ->where('type', 'sample')
            ->with(['salesOrder.buyer'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($po) => [
                'id'         => $po->id,
                'po_number'  => $po->po_number,
                'label'      => $po->po_number,
                'buyer_name' => $po->salesOrder?->buyer?->name ?? '-',
                'so_number'  => $po->salesOrder?->so_number  ?? '-',
            ]);

        return response()->json(['success' => true, 'data' => $pos]);
    }

    // Komponen dari Gudang MESIN — input untuk prototype
    public function getSourceItems()
    {
        $warehouse = Warehouse::where('code', 'MESIN')->first();
        if (!$warehouse) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $items = Inventory::where('warehouse_id', $warehouse->id)
            ->where('qty_pcs', '>', 0)
            ->with('item')
            ->get()
            ->map(fn($inv) => [
                'item_id'        => $inv->item_id,
                'item_code'      => $inv->item?->code ?? '-',
                'item_name'      => $inv->item?->name ?? '-',
                'qty_available'  => (float) $inv->qty_pcs,
                'warehouse_id'   => $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'warehouse_name' => $warehouse->name,
            ]);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date'      => ['required', 'date'],
            'ref_po_id' => ['required', 'integer', 'exists:production_orders,id'],
            'notes'     => ['nullable', 'string'],

            'inputs'             => ['required', 'array', 'min:1'],
            'inputs.*.item_id'   => ['required', 'integer', 'exists:items,id'],
            'inputs.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'inputs.*.qty'       => ['required', 'numeric', 'min:0.01'],

            'outputs'           => ['required', 'array', 'min:1'],
            'outputs.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'outputs.*.qty'     => ['required', 'numeric', 'min:1'],
        ]);

        return DB::transaction(function () use ($data) {
            $warehousePrototype = Warehouse::where('code', 'PROTOTYPE')->first();
            if (!$warehousePrototype) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang PROTOTYPE tidak ditemukan.'],
                ]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';

            $runningNumber  = InventoryLog::where('transaction_type', 'PROTOTYPE')
                ->whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->distinct('reference_number')
                ->count('reference_number') + 1;
            $documentNumber = 'PROTO-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            // INPUT: Kurangi komponen dari gudang sumber (MESIN)
            foreach ($data['inputs'] as $idx => $input) {
                $itemId      = $input['item_id'];
                $warehouseId = $input['warehouse_id'];
                $qty         = $input['qty'];
                $itemName    = Item::find($itemId)?->name ?? "ID {$itemId}";
                $whName      = Warehouse::find($warehouseId)?->name ?? "ID {$warehouseId}";

                $sourceInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()->first();

                $available = $sourceInv?->qty_pcs ?? 0;
                if ($available < $qty) {
                    throw ValidationException::withMessages([
                        "inputs.{$idx}.qty" => [
                            "'{$itemName}' stok di {$whName} tidak cukup. Tersedia: {$available} pcs.",
                        ],
                    ]);
                }

                $sourceInv->decrement('qty_pcs', $qty);

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $warehouseId,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'OUT',
                    'transaction_type' => 'PROTOTYPE',
                    'reference_type'   => 'ProductionOrder',
                    'reference_id'     => $data['ref_po_id'],
                    'reference_number' => $documentNumber,
                    'notes'            => "Komponen masuk Prototype ({$documentNumber}) PO:{$poNumber}",
                    'user_id'          => Auth::id(),
                ]);
            }

            // OUTPUT: Tambah produk ke Gudang PROTOTYPE
            foreach ($data['outputs'] as $output) {
                $itemId = $output['item_id'];
                $qty    = $output['qty'];

                $targetInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $warehousePrototype->id)
                    ->lockForUpdate()->first();

                if ($targetInv) {
                    $targetInv->increment('qty_pcs', $qty);
                } else {
                    Inventory::create([
                        'item_id'      => $itemId,
                        'warehouse_id' => $warehousePrototype->id,
                        'qty_pcs'      => $qty,
                        'ref_po_id'    => $data['ref_po_id'],
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $warehousePrototype->id,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'IN',
                    'transaction_type' => 'PROTOTYPE',
                    'reference_type'   => 'ProductionOrder',
                    'reference_id'     => $data['ref_po_id'],
                    'reference_number' => $documentNumber,
                    'notes'            => "Hasil Prototype → Gudang PROTOTYPE ({$documentNumber}) PO:{$poNumber}",
                    'user_id'          => Auth::id(),
                ]);
            }

            if ($productionOrder) {
                $productionOrder->current_stage = 'prototype';
                $productionOrder->status        = 'in_progress';
                $productionOrder->save();
            }

            return response()->json([
                'success' => true,
                'message' => "Proses Prototype berhasil ({$documentNumber})",
                'data'    => ['document_number' => $documentNumber],
            ], 201);
        });
    }
}
