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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FinishingController extends Controller
{
    public function sourceItems(Request $request)
    {
        $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
        ]);

        $inventories = Inventory::where('warehouse_id', $request->warehouse_id)
            ->where('qty_pcs', '>', 0)
            ->with('item')
            ->get()
            ->map(function ($inv) {
                return [
                    'item_id'       => $inv->item_id,
                    'item_code'     => $inv->item?->code ?? '-',
                    'item_name'     => $inv->item?->name ?? '-',
                    'qty_available' => (float) $inv->qty_pcs,
                ];
            });

        return response()->json(['success' => true, 'data' => $inventories]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date'                => ['required', 'date'],
            'ref_po_id'           => ['required', 'integer', 'exists:production_orders,id'],
            'source_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'notes'               => ['nullable', 'string'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.item_id'     => ['required', 'integer', 'exists:items,id'],
            'items.*.qty'         => ['required', 'numeric', 'min:1'],
        ]);

        return DB::transaction(function () use ($data) {
            Log::info('=== FINISHING START ===', ['po_id' => $data['ref_po_id']]);

            $sourceWarehouse = Warehouse::find($data['source_warehouse_id']);
            $targetWarehouse = Warehouse::where('code', 'FINISHING')->first();

            if (!$targetWarehouse) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Finishing tidak ditemukan.'],
                ]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';
            $sourceName      = $sourceWarehouse?->name ?? '-';

            $runningNumber  = InventoryLog::where('transaction_type', 'FINISHING')
                ->whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->where('direction', 'OUT')
                ->count() + 1;
            $documentNumber = 'FNS-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            foreach ($data['items'] as $index => $item) {
                $itemId   = $item['item_id'];
                $qty      = $item['qty'];
                $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                $sourceInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $data['source_warehouse_id'])
                    ->lockForUpdate()->first();

                $availableQty = $sourceInv?->qty_pcs ?? 0;

                if ($availableQty < $qty) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty" => [
                            "'{$itemName}' stok di {$sourceName} tidak cukup. Tersedia: {$availableQty} pcs."
                        ],
                    ]);
                }

                $sourceInv->decrement('qty_pcs', $qty);

                InventoryLog::create([
                    'date' => $data['date'], 'time' => now()->toTimeString(),
                    'item_id' => $itemId, 'warehouse_id' => $data['source_warehouse_id'],
                    'qty' => $qty, 'qty_m3' => 0, 'direction' => 'OUT',
                    'transaction_type' => 'FINISHING', 'reference_type' => 'Finishing',
                    'reference_number' => $documentNumber,
                    'notes' => "Masuk Finishing dari {$sourceName} ({$documentNumber}) - PO: {$poNumber}",
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
                    'transaction_type' => 'FINISHING', 'reference_type' => 'Finishing',
                    'reference_number' => $documentNumber,
                    'notes' => "Hasil Finishing masuk Gudang Finishing ({$documentNumber}) - PO: {$poNumber}",
                    'user_id' => Auth::id(),
                ]);
            }

            if ($productionOrder) {
                $productionOrder->current_stage = 'finishing';
                $productionOrder->status = 'in_progress';
                $productionOrder->save();
            }

            Log::info('=== FINISHING END ===', ['doc' => $documentNumber]);

            return response()->json([
                'success' => true,
                'message' => "Proses Finishing berhasil ({$documentNumber})",
                'data'    => ['document_number' => $documentNumber, 'total_items' => count($data['items'])],
            ], 201);
        });
    }
}