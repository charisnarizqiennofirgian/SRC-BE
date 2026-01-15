<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Models\InventoryLog;
use App\Models\ProductionOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CandyProductionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'  => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.warehouse_id'   => ['required', 'integer', 'exists:warehouses,id'],
            'items.*.item_id'        => ['required', 'integer', 'exists:items,id'],
            'items.*.target_item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty'            => ['required', 'integer', 'min:1'],
        ]);

        Log::info('=== CANDY PRODUCTION START ===');
        Log::info('Payload:', $data);

        return DB::transaction(function () use ($data) {
            $candyWarehouse = Warehouse::where('name', 'like', '%Gudang Candy%')->first();
            if (!$candyWarehouse) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Candy tidak ditemukan di master.'],
                ]);
            }

            Log::info("Gudang Candy ID: {$candyWarehouse->id}");

            $results = [];

            foreach ($data['items'] as $index => $itemData) {
                $fromWarehouseId = $itemData['warehouse_id'];
                $sourceItemId    = $itemData['item_id'];
                $targetItemId    = $itemData['target_item_id'];
                $qtyNeeded       = $itemData['qty'];

                Log::info("--- PROCESSING ITEM #{$index} ---");
                Log::info("From Warehouse ID: {$fromWarehouseId}, Source Item ID: {$sourceItemId}, Target Item ID: {$targetItemId}, Qty: {$qtyNeeded}");

                $sourceInventories = Inventory::where('warehouse_id', $fromWarehouseId)
                    ->where('item_id', $sourceItemId)
                    ->where('qty', '>', 0)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                if ($sourceInventories->isEmpty()) {
                    throw ValidationException::withMessages([
                        "items.{$index}.item_id" => [
                            "Item #" . ($index + 1) . ": Tidak ada stok tersedia di gudang untuk item ini."
                        ],
                    ]);
                }

                $totalAvailable = $sourceInventories->sum('qty');
                Log::info("Total inventory available: {$totalAvailable} pcs");

                if ($qtyNeeded > $totalAvailable) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty" => [
                            "Item #" . ($index + 1) . ": Qty ({$qtyNeeded} pcs) melebihi stok tersedia ({$totalAvailable} pcs)."
                        ],
                    ]);
                }

                $qtyRemaining = $qtyNeeded;
                $processedInventories = [];

                foreach ($sourceInventories as $sourceInv) {
                    if ($qtyRemaining <= 0) break;

                    $qtyToTake = min($qtyRemaining, $sourceInv->qty);

                    Log::info("Taking {$qtyToTake} pcs from Inventory ID {$sourceInv->id} (current qty: {$sourceInv->qty})");

                    // Kurangi INVENTORY sumber
                    $sourceInv->qty -= $qtyToTake;
                    $sourceInv->save();

                    Log::info("Inventory ID {$sourceInv->id} updated to {$sourceInv->qty} pcs");

                    // Ambil data PO untuk reference
                    $productionOrder = $sourceInv->ref_po_id ? ProductionOrder::find($sourceInv->ref_po_id) : null;
                    $poNumber = $productionOrder?->po_number ?? null;

                    // Catat ke inventory_logs (OUT dari gudang sumber)
                    InventoryLog::create([
                        'date' => $data['date'],
                        'time' => now()->toTimeString(),
                        'item_id' => $sourceItemId,
                        'warehouse_id' => $fromWarehouseId,
                        'qty' => $qtyToTake,
                        'direction' => 'OUT',
                        'transaction_type' => 'TRANSFER_OUT',
                        'reference_type' => $sourceInv->ref_po_id ? 'ProductionOrder' : 'CandyProduction',
                        'reference_id' => $sourceInv->ref_po_id ?? null,
                        'reference_number' => $poNumber,
                        'notes' => "Transfer ke Gudang Candy untuk proses pengeringan",
                        'user_id' => Auth::id(),
                    ]);

                    // Tambah / buat INVENTORY Candy
                    $targetInv = Inventory::where('warehouse_id', $candyWarehouse->id)
                        ->where('item_id', $targetItemId)
                        ->where('ref_po_id', $sourceInv->ref_po_id)
                        ->where('ref_product_id', $sourceInv->ref_product_id)
                        ->lockForUpdate()
                        ->first();

                    if ($targetInv) {
                        $oldQty = $targetInv->qty;
                        $targetInv->qty += $qtyToTake;
                        $targetInv->save();
                        Log::info("Target Inventory ID {$targetInv->id} incremented from {$oldQty} to {$targetInv->qty}");
                    } else {
                        $targetInv = Inventory::create([
                            'warehouse_id'   => $candyWarehouse->id,
                            'item_id'        => $targetItemId,
                            'qty'            => $qtyToTake,
                            'ref_po_id'      => $sourceInv->ref_po_id,
                            'ref_product_id' => $sourceInv->ref_product_id,
                        ]);
                        Log::info("New Target Inventory ID {$targetInv->id} created with qty {$qtyToTake}");
                    }

                    // Catat ke inventory_logs (IN ke Gudang Candy)
                    InventoryLog::create([
                        'date' => $data['date'],
                        'time' => now()->toTimeString(),
                        'item_id' => $targetItemId,
                        'warehouse_id' => $candyWarehouse->id,
                        'qty' => $qtyToTake,
                        'direction' => 'IN',
                        'transaction_type' => 'TRANSFER_IN',
                        'reference_type' => $sourceInv->ref_po_id ? 'ProductionOrder' : 'CandyProduction',
                        'reference_id' => $sourceInv->ref_po_id ?? null,
                        'reference_number' => $poNumber,
                        'notes' => "Hasil proses Candy (pengeringan)",
                        'user_id' => Auth::id(),
                    ]);

                    $processedInventories[] = [
                        'inventory_id' => $sourceInv->id,
                        'qty_taken'    => $qtyToTake,
                        'ref_po_id'    => $sourceInv->ref_po_id,
                    ];

                    $qtyRemaining -= $qtyToTake;
                }

                // Kurangi STOCK pcs di Gudang sumber
                $fromStock = Stock::where('warehouse_id', $fromWarehouseId)
                    ->where('item_id', $sourceItemId)
                    ->lockForUpdate()
                    ->first();

                if ($fromStock) {
                    Log::info("From Stock ID {$fromStock->id}: Current qty = {$fromStock->quantity}, will decrement by {$qtyNeeded}");

                    if ($fromStock->quantity < $qtyNeeded) {
                        throw ValidationException::withMessages([
                            "items.{$index}.qty" => [
                                "Item #" . ($index + 1) . ": Stok pcs di Gudang Sanwil tidak mencukupi. Tersedia: {$fromStock->quantity} pcs."
                            ],
                        ]);
                    }
                    $fromStock->decrement('quantity', $qtyNeeded);

                    $fromStock->refresh();
                    Log::info("From Stock ID {$fromStock->id} after decrement: {$fromStock->quantity}");
                } else {
                    Log::warning("From Stock NOT FOUND for warehouse_id={$fromWarehouseId}, item_id={$sourceItemId}");
                }

                // Tambah STOCK pcs di Gudang Candy
                $toStock = Stock::where('warehouse_id', $candyWarehouse->id)
                    ->where('item_id', $targetItemId)
                    ->lockForUpdate()
                    ->first();

                if ($toStock) {
                    Log::info("To Stock ID {$toStock->id}: Current qty = {$toStock->quantity}, will increment by {$qtyNeeded}");
                    $toStock->increment('quantity', $qtyNeeded);

                    $toStock->refresh();
                    Log::info("To Stock ID {$toStock->id} after increment: {$toStock->quantity}");
                } else {
                    $toStock = Stock::create([
                        'warehouse_id' => $candyWarehouse->id,
                        'item_id'      => $targetItemId,
                        'quantity'     => $qtyNeeded,
                    ]);
                    Log::info("New To Stock ID {$toStock->id} created with qty {$qtyNeeded}");
                }

                $results[] = [
                    'item_number'      => $index + 1,
                    'source_item_id'   => $sourceItemId,
                    'target_item_id'   => $targetItemId,
                    'qty_processed'    => $qtyNeeded,
                    'inventories_used' => $processedInventories,
                ];
            }

            Log::info('=== CANDY PRODUCTION END ===');

            return response()->json([
                'success' => true,
                'message' => 'Proses Candy berhasil disimpan untuk ' . count($results) . ' item.',
                'data'    => [
                    'date'            => $data['date'],
                    'notes'           => $data['notes'],
                    'total_items'     => count($results),
                    'processed_items' => $results,
                ],
            ], 201);
        });
    }
}
