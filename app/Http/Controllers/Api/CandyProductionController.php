<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Warehouse;
use App\Models\InventoryLog;
use App\Models\ProductionOrder;
use App\Services\ProductionOrderProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CandyProductionController extends Controller
{
    protected ProductionOrderProgressService $poProgress;

    public function __construct(ProductionOrderProgressService $poProgress)
    {
        $this->poProgress = $poProgress;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date'  => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'ref_po_id' => ['required', 'integer', 'exists:production_orders,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.warehouse_id'   => ['required', 'integer', 'exists:warehouses,id'],
            'items.*.item_id'        => ['required', 'integer', 'exists:items,id'],
            'items.*.target_item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty'            => ['required', 'integer', 'min:1'],
        ]);

        Log::info('=== KD PRODUCTION START ===');
        Log::info('Payload:', $data);

        return DB::transaction(function () use ($data) {
            $kdWarehouse = Warehouse::where('code', 'RSTK')->first();

            if (!$kdWarehouse) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang KD (RST Kering) tidak ditemukan di master.'],
                ]);
            }

            Log::info("Gudang KD ID: {$kdWarehouse->id}");

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber = $productionOrder?->po_number ?? null;

            Log::info("Production Order: {$poNumber} (ID: {$data['ref_po_id']})");

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

                    $sourceInv->qty -= $qtyToTake;
                    $sourceInv->save();

                    Log::info("Inventory ID {$sourceInv->id} updated to {$sourceInv->qty} pcs");

                    InventoryLog::create([
                        'date' => $data['date'],
                        'time' => now()->toTimeString(),
                        'item_id' => $sourceItemId,
                        'warehouse_id' => $fromWarehouseId,
                        'qty' => $qtyToTake,
                        'direction' => 'OUT',
                        'transaction_type' => 'TRANSFER_OUT',
                        'reference_type' => 'ProductionOrder',
                        'reference_id' => $data['ref_po_id'],
                        'reference_number' => $poNumber,
                        'notes' => "Transfer ke Gudang KD untuk proses pengeringan",
                        'user_id' => Auth::id(),
                    ]);

                    $targetInv = Inventory::where('warehouse_id', $kdWarehouse->id)
                        ->where('item_id', $targetItemId)
                        ->where('ref_po_id', $data['ref_po_id'])
                        ->lockForUpdate()
                        ->first();

                    if ($targetInv) {
                        $oldQty = $targetInv->qty;
                        $targetInv->qty += $qtyToTake;
                        $targetInv->save();
                        Log::info("Target Inventory ID {$targetInv->id} incremented from {$oldQty} to {$targetInv->qty}");
                    } else {
                        $targetInv = Inventory::create([
                            'warehouse_id'   => $kdWarehouse->id,
                            'item_id'        => $targetItemId,
                            'qty'            => $qtyToTake,
                            'ref_po_id'      => $data['ref_po_id'],
                            'ref_product_id' => $productionOrder?->product_id ?? null,
                        ]);
                        Log::info("New Target Inventory ID {$targetInv->id} created with qty {$qtyToTake}");
                    }

                    InventoryLog::create([
                        'date' => $data['date'],
                        'time' => now()->toTimeString(),
                        'item_id' => $targetItemId,
                        'warehouse_id' => $kdWarehouse->id,
                        'qty' => $qtyToTake,
                        'direction' => 'IN',
                        'transaction_type' => 'TRANSFER_IN',
                        'reference_type' => 'ProductionOrder',
                        'reference_id' => $data['ref_po_id'],
                        'reference_number' => $poNumber,
                        'notes' => "Hasil proses KD (pengeringan)",
                        'user_id' => Auth::id(),
                    ]);

                    $processedInventories[] = [
                        'inventory_id' => $sourceInv->id,
                        'qty_taken'    => $qtyToTake,
                        'ref_po_id'    => $data['ref_po_id'],
                    ];

                    $qtyRemaining -= $qtyToTake;
                }

                $results[] = [
                    'item_number'      => $index + 1,
                    'source_item_id'   => $sourceItemId,
                    'target_item_id'   => $targetItemId,
                    'qty_processed'    => $qtyNeeded,
                    'inventories_used' => $processedInventories,
                ];
            }

            Log::info('=== KD PRODUCTION END ===');

            $this->poProgress->markOnProgress($data['ref_po_id']);

            return response()->json([
                'success' => true,
                'message' => 'Proses KD berhasil disimpan untuk ' . count($results) . ' item.',
                'data'    => [
                    'date'            => $data['date'],
                    'notes'           => $data['notes'],
                    'ref_po_id'       => $data['ref_po_id'],
                    'po_number'       => $poNumber,
                    'total_items'     => count($results),
                    'processed_items' => $results,
                ],
            ], 201);
        });
    }
}
