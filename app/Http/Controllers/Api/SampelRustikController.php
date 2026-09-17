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

class SampelRustikController extends Controller
{
    public function getAvailablePos()
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

    public function getSourceItems()
    {
        $warehousePrototype = Warehouse::where('code', 'PROTOTYPE')->first();
        if (!$warehousePrototype) {
            throw ValidationException::withMessages([
                'warehouse' => ['Gudang PROTOTYPE tidak ditemukan.'],
            ]);
        }

        $inventories = Inventory::where('warehouse_id', $warehousePrototype->id)
            ->where('qty_pcs', '>', 0)
            ->with('item')
            ->get()
            ->map(fn($inv) => [
                'item_id'       => $inv->item_id,
                'item_code'     => $inv->item?->code ?? '-',
                'item_name'     => $inv->item?->name ?? '-',
                'qty_available' => (float) $inv->qty_pcs,
            ]);

        return response()->json(['success' => true, 'data' => $inventories]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date'             => ['required', 'date'],
            'ref_po_id'        => ['required', 'integer', 'exists:production_orders,id'],
            'notes'            => ['nullable', 'string'],
            'items'            => ['required', 'array', 'min:1'],
            'items.*.item_id'  => ['required', 'integer', 'exists:items,id'],
            'items.*.qty'      => ['required', 'numeric', 'min:0.01'],
        ]);

        return DB::transaction(function () use ($data) {
            $sourceWarehouse = Warehouse::where('code', 'PROTOTYPE')->first();
            $targetWarehouse = Warehouse::where('code', 'RUSTIK_SAMPLE')->first();

            if (!$sourceWarehouse) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang PROTOTYPE tidak ditemukan.'],
                ]);
            }
            if (!$targetWarehouse) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang RUSTIK_SAMPLE tidak ditemukan.'],
                ]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';

            $prefix = 'RSTS-' . now()->format('Ym') . '-';
            $last   = InventoryLog::where('transaction_type', 'RUSTIK_SAMPLE')
                ->where('direction', 'OUT')
                ->where('reference_number', 'like', $prefix . '%')
                ->orderByDesc('reference_number')
                ->value('reference_number');
            $runningNumber  = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
            $documentNumber = $prefix . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            foreach ($data['items'] as $index => $item) {
                $itemId   = $item['item_id'];
                $qty      = $item['qty'];
                $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                $sourceInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $sourceWarehouse->id)
                    ->lockForUpdate()->first();

                $availableQty = $sourceInv?->qty_pcs ?? 0;

                if ($availableQty < $qty) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty" => [
                            "'{$itemName}' stok di {$sourceWarehouse->name} tidak cukup. Tersedia: {$availableQty} pcs."
                        ],
                    ]);
                }

                $sourceInv->decrement('qty_pcs', $qty);

                InventoryLog::create([
                    'date' => $data['date'], 'time' => now()->toTimeString(),
                    'item_id' => $itemId, 'warehouse_id' => $sourceWarehouse->id,
                    'qty' => $qty, 'qty_m3' => 0, 'direction' => 'OUT',
                    'transaction_type' => 'RUSTIK_SAMPLE', 'reference_type' => 'ProductionOrder',
                    'reference_id' => $data['ref_po_id'],
                    'reference_number' => $documentNumber,
                    'notes' => "Masuk Rustik Sampel dari {$sourceWarehouse->name} ({$documentNumber}) - PO: {$poNumber}",
                    'user_id' => Auth::id(),
                ]);

                $targetInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $targetWarehouse->id)
                    ->lockForUpdate()->first();

                if ($targetInv) {
                    $targetInv->increment('qty_pcs', $qty);
                } else {
                    Inventory::create([
                        'item_id' => $itemId, 'warehouse_id' => $targetWarehouse->id,
                        'qty_pcs' => $qty, 'ref_po_id' => $data['ref_po_id'],
                    ]);
                }

                InventoryLog::create([
                    'date' => $data['date'], 'time' => now()->toTimeString(),
                    'item_id' => $itemId, 'warehouse_id' => $targetWarehouse->id,
                    'qty' => $qty, 'qty_m3' => 0, 'direction' => 'IN',
                    'transaction_type' => 'RUSTIK_SAMPLE', 'reference_type' => 'ProductionOrder',
                    'reference_id' => $data['ref_po_id'],
                    'reference_number' => $documentNumber,
                    'notes' => "Hasil Rustik Sampel masuk {$targetWarehouse->name} ({$documentNumber}) - PO: {$poNumber}",
                    'user_id' => Auth::id(),
                ]);
            }

            if ($productionOrder) {
                $productionOrder->current_stage = 'rustik';
                $productionOrder->status = 'in_progress';
                $productionOrder->save();
            }

            return response()->json([
                'success' => true,
                'message' => "Proses Rustik Sampel berhasil ({$documentNumber})",
                'data'    => ['document_number' => $documentNumber, 'total_items' => count($data['items'])],
            ], 201);
        });
    }
}
