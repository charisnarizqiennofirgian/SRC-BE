<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\ProductionOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PembahananController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'po_id' => ['required', 'integer', 'exists:production_orders,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.warehouse_id'   => ['required', 'integer', 'exists:warehouses,id'],
            'items.*.item_id'        => ['required', 'integer', 'exists:items,id'],
            'items.*.input_qty'      => ['required', 'integer', 'min:1'],
            'items.*.output_item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.output_qty'     => ['required', 'integer', 'min:1'],
        ]);

        Log::info('=== PEMBAHANAN START ===');
        Log::info('Payload:', $data);

        return DB::transaction(function () use ($data) {
            $poId = $data['po_id'];
            $results = [];

            // Ambil data PO untuk reference
            $productionOrder = ProductionOrder::find($poId);
            $poNumber = $productionOrder?->po_number;

            foreach ($data['items'] as $index => $itemData) {
                $sourceWarehouseId = $itemData['warehouse_id'];
                $sourceItemId      = $itemData['item_id'];
                $inputQty          = $itemData['input_qty'];
                $outputItemId      = $itemData['output_item_id'];
                $outputQty         = $itemData['output_qty'];

                Log::info("--- PROCESSING ITEM #{$index} ---");
                Log::info("Source Warehouse ID: {$sourceWarehouseId}, Source Item ID: {$sourceItemId}, Input Qty: {$inputQty}");

                $sourceInventories = Inventory::where('warehouse_id', $sourceWarehouseId)
                    ->where('item_id', $sourceItemId)
                    ->where('qty', '>', 0)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                if ($sourceInventories->isEmpty()) {
                    throw ValidationException::withMessages([
                        "items.{$index}.item_id" => [
                            "Item #" . ($index + 1) . ": Tidak ada stok tersedia di Gudang Candy untuk item ini."
                        ],
                    ]);
                }

                $totalAvailable = $sourceInventories->sum('qty');
                Log::info("Total stok tersedia: {$totalAvailable} pcs");

                if ($inputQty > $totalAvailable) {
                    throw ValidationException::withMessages([
                        "items.{$index}.input_qty" => [
                            "Item #" . ($index + 1) . ": Qty input ({$inputQty} pcs) melebihi stok tersedia ({$totalAvailable} pcs)."
                        ],
                    ]);
                }

                $qtyRemaining = $inputQty;
                $processedInventories = [];

                foreach ($sourceInventories as $sourceInv) {
                    if ($qtyRemaining <= 0) break;

                    $qtyToTake = min($qtyRemaining, $sourceInv->qty);

                    Log::info("Taking {$qtyToTake} pcs from Inventory ID {$sourceInv->id} (current qty: {$sourceInv->qty})");

                    // Kurangi INVENTORY sumber
                    $sourceInv->qty -= $qtyToTake;
                    $sourceInv->save();

                    Log::info("Inventory ID {$sourceInv->id} updated to {$sourceInv->qty} pcs");

                    // Catat ke inventory_logs (OUT dari gudang sumber)
                    InventoryLog::create([
                        'date' => now()->toDateString(),
                        'time' => now()->toTimeString(),
                        'item_id' => $sourceItemId,
                        'warehouse_id' => $sourceWarehouseId,
                        'qty' => $qtyToTake,
                        'direction' => 'OUT',
                        'transaction_type' => 'PRODUCTION',
                        'reference_type' => 'ProductionOrder',
                        'reference_id' => $poId,
                        'reference_number' => $poNumber,
                        'notes' => "Bahan untuk proses Pembahanan",
                        'user_id' => Auth::id(),
                    ]);

                    // Tambah / buat INVENTORY Pembahanan
                    $targetWarehouseId = 4; // Gudang Pembahanan (BUFFER RST)

                    $targetInv = Inventory::where('warehouse_id', $targetWarehouseId)
                        ->where('item_id', $outputItemId)
                        ->where('ref_po_id', $poId)
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
                            'warehouse_id'   => $targetWarehouseId,
                            'item_id'        => $outputItemId,
                            'qty'            => $qtyToTake,
                            'ref_po_id'      => $poId,
                            'ref_product_id' => $sourceInv->ref_product_id,
                        ]);
                        Log::info("New Target Inventory ID {$targetInv->id} created with qty {$qtyToTake}");
                    }

                    // Catat ke inventory_logs (IN ke Gudang Pembahanan)
                    InventoryLog::create([
                        'date' => now()->toDateString(),
                        'time' => now()->toTimeString(),
                        'item_id' => $outputItemId,
                        'warehouse_id' => $targetWarehouseId,
                        'qty' => $qtyToTake,
                        'direction' => 'IN',
                        'transaction_type' => 'PRODUCTION',
                        'reference_type' => 'ProductionOrder',
                        'reference_id' => $poId,
                        'reference_number' => $poNumber,
                        'notes' => "Hasil proses Pembahanan",
                        'user_id' => Auth::id(),
                    ]);

                    $processedInventories[] = [
                        'inventory_id' => $sourceInv->id,
                        'qty_taken'    => $qtyToTake,
                        'ref_po_id'    => $sourceInv->ref_po_id,
                    ];

                    $qtyRemaining -= $qtyToTake;
                }

                $results[] = [
                    'item_number'          => $index + 1,
                    'source_item_id'       => $sourceItemId,
                    'output_item_id'       => $outputItemId,
                    'input_qty_processed'  => $inputQty,
                    'output_qty_planned'   => $outputQty,
                    'inventories_used'     => $processedInventories,
                ];
            }

            Log::info('=== PEMBAHANAN END ===');

            return response()->json([
                'success' => true,
                'message' => 'Proses Pembahanan berhasil disimpan untuk ' . count($results) . ' item.',
                'data'    => [
                    'po_id'           => $poId,
                    'total_items'     => count($results),
                    'processed_items' => $results,
                ],
            ], 201);
        });
    }

    public function sourceInventories(Request $request)
    {
        $candyWarehouseId = 3; // Gudang Candy (RST Kering) sebagai sumber

        $inventories = Inventory::where('warehouse_id', $candyWarehouseId)
            ->where('qty', '>', 0)
            ->with(['item', 'warehouse'])
            ->orderBy('id', 'asc')
            ->get(['id', 'warehouse_id', 'item_id', 'qty', 'ref_po_id', 'ref_product_id']);

        Log::info('Source Inventories (Gudang Candy):', $inventories->toArray());

        return response()->json([
            'success' => true,
            'data'    => $inventories,
        ]);
    }
}
