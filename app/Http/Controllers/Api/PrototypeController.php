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

    public function sourceInventories(Request $request)
    {
        $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id']);

        $inventories = Inventory::where('warehouse_id', $request->warehouse_id)
            ->where('qty_pcs', '>', 0)
            ->with(['item', 'warehouse'])
            ->get()
            ->map(fn($inv) => [
                'id'             => $inv->id,
                'item_id'        => $inv->item_id,
                'item_code'      => $inv->item?->code ?? '-',
                'item_name'      => $inv->item?->name ?? '-',
                'warehouse_id'   => $inv->warehouse_id,
                'warehouse_name' => $inv->warehouse?->name ?? '-',
                'qty_pcs'        => (float) $inv->qty_pcs,
            ]);

        return response()->json(['success' => true, 'data' => $inventories]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date'            => ['required', 'date'],
            'ref_po_id'       => ['required', 'integer', 'exists:production_orders,id'],
            'notes'           => ['nullable', 'string'],
            'items'           => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty'     => ['required', 'numeric', 'min:1'],
        ]);

        return DB::transaction(function () use ($data) {
            // Gudang sumber: S4S (output Moulding)
            $sourceWarehouse = Warehouse::where('code', 'S4S')->first();
            if (!$sourceWarehouse) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang S4S tidak ditemukan.'],
                ]);
            }

            // Gudang tujuan: PROTOTYPE
            $targetWarehouse = Warehouse::where('code', 'PROTOTYPE')->first();
            if (!$targetWarehouse) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Prototype tidak ditemukan.'],
                ]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';

            // Generate nomor dokumen
            $count          = DB::table('inventory_logs')
                ->where('transaction_type', 'PROTOTYPE')
                ->whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->distinct('reference_number')
                ->count('reference_number') + 1;
            $documentNumber = 'PROTO-' . now()->format('Ym') . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

            foreach ($data['items'] as $index => $itemData) {
                $itemId = $itemData['item_id'];
                $qty    = $itemData['qty'];
                $item   = Item::find($itemId);

                // Cek stok S4S
                $sourceInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $sourceWarehouse->id)
                    ->lockForUpdate()
                    ->first();

                $available = $sourceInv?->qty_pcs ?? 0;
                if ($available < $qty) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty" => [
                            "'{$item?->name}' stok tidak cukup di Gudang S4S. Tersedia: {$available} pcs."
                        ],
                    ]);
                }

                // Kurangi stok S4S
                $sourceInv->decrement('qty_pcs', $qty);

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $sourceWarehouse->id,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'OUT',
                    'transaction_type' => 'PROTOTYPE',
                    'reference_type'   => 'PrototypeProduction',
                    'reference_id'     => $data['ref_po_id'],
                    'reference_number' => $documentNumber,
                    'notes'            => "Keluar untuk Prototype PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                // Tambah stok Prototype
                $targetInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $targetWarehouse->id)
                    ->lockForUpdate()
                    ->first();

                if ($targetInv) {
                    $targetInv->increment('qty_pcs', $qty);
                } else {
                    Inventory::create([
                        'item_id'      => $itemId,
                        'warehouse_id' => $targetWarehouse->id,
                        'qty_pcs'      => $qty,
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $targetWarehouse->id,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'IN',
                    'transaction_type' => 'PROTOTYPE',
                    'reference_type'   => 'PrototypeProduction',
                    'reference_id'     => $data['ref_po_id'],
                    'reference_number' => $documentNumber,
                    'notes'            => "Masuk Gudang Prototype PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                Log::info("Prototype item: item_id={$itemId}, qty={$qty}");
            }

            // Update stage PO
            if ($productionOrder) {
                $productionOrder->current_stage = 'prototype';
                $productionOrder->status        = 'in_progress';
                $productionOrder->save();
            }

            return response()->json([
                'success' => true,
                'message' => "Proses Prototype berhasil ({$documentNumber})",
                'data'    => [
                    'document_number' => $documentNumber,
                    'total_items'     => count($data['items']),
                ],
            ], 201);
        });
    }
}