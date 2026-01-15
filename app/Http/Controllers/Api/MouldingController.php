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

class MouldingController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'po_id' => ['required', 'integer', 'exists:production_orders,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.source_inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'items.*.input_qty'           => ['required', 'integer', 'min:1'],
            'items.*.output_item_id'      => ['required', 'integer', 'exists:items,id'],
            'items.*.output_qty'          => ['required', 'integer', 'min:1'],
        ]);

        Log::info('=== MOULDING START ===');
        Log::info('Payload:', $data);

        return DB::transaction(function () use ($data) {
            $poId = $data['po_id'];
            $results = [];

            // Ambil data PO untuk reference
            $productionOrder = ProductionOrder::find($poId);
            $poNumber = $productionOrder?->po_number;

            foreach ($data['items'] as $index => $itemData) {
                $sourceInventoryId = $itemData['source_inventory_id'];
                $inputQty          = $itemData['input_qty'];
                $outputItemId      = $itemData['output_item_id'];
                $outputQty         = $itemData['output_qty'];

                Log::info("--- PROCESSING ITEM #{$index} ---");
                Log::info("Source Inventory ID: {$sourceInventoryId}, Input Qty: {$inputQty}");

                // 1. Ambil source inventory (Gudang Pembahanan)
                $sourceInv = Inventory::lockForUpdate()->find($sourceInventoryId);

                if (!$sourceInv) {
                    throw ValidationException::withMessages([
                        "items.{$index}.source_inventory_id" => [
                            "Item #" . ($index + 1) . ": Inventory sumber tidak ditemukan."
                        ],
                    ]);
                }

                Log::info("Source Inventory ID {$sourceInv->id}: Current qty = {$sourceInv->qty}");

                if ($inputQty > $sourceInv->qty) {
                    throw ValidationException::withMessages([
                        "items.{$index}.input_qty" => [
                            "Item #" . ($index + 1) . ": Qty input ({$inputQty} pcs) melebihi stok tersedia ({$sourceInv->qty} pcs)."
                        ],
                    ]);
                }

                // 2. Kurangi INVENTORY Pembahanan
                $sourceInv->qty -= $inputQty;
                $sourceInv->save();

                Log::info("Source Inventory ID {$sourceInv->id} updated to {$sourceInv->qty} pcs");

                // Catat ke inventory_logs (OUT dari Gudang Pembahanan)
                InventoryLog::create([
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'item_id' => $sourceInv->item_id,
                    'warehouse_id' => $sourceInv->warehouse_id,
                    'qty' => $inputQty,
                    'direction' => 'OUT',
                    'transaction_type' => 'PRODUCTION',
                    'reference_type' => 'ProductionOrder',
                    'reference_id' => $poId,
                    'reference_number' => $poNumber,
                    'notes' => "Bahan untuk proses Moulding",
                    'user_id' => Auth::id(),
                ]);

                // 3. Tambah / buat INVENTORY Moulding
                $targetWarehouseId = 5; // Gudang Moulding

                $targetInv = Inventory::where('warehouse_id', $targetWarehouseId)
                    ->where('item_id', $outputItemId)
                    ->where('ref_po_id', $poId)
                    ->where('ref_product_id', $sourceInv->ref_product_id)
                    ->lockForUpdate()
                    ->first();

                if ($targetInv) {
                    $oldQty = $targetInv->qty;
                    $targetInv->qty += $outputQty;
                    $targetInv->save();
                    Log::info("Target Inventory ID {$targetInv->id} incremented from {$oldQty} to {$targetInv->qty}");
                } else {
                    $targetInv = Inventory::create([
                        'warehouse_id'   => $targetWarehouseId,
                        'item_id'        => $outputItemId,
                        'qty'            => $outputQty,
                        'ref_po_id'      => $poId,
                        'ref_product_id' => $sourceInv->ref_product_id,
                    ]);
                    Log::info("New Target Inventory ID {$targetInv->id} created with qty {$outputQty}");
                }

                // Catat ke inventory_logs (IN ke Gudang Moulding)
                InventoryLog::create([
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'item_id' => $outputItemId,
                    'warehouse_id' => $targetWarehouseId,
                    'qty' => $outputQty,
                    'direction' => 'IN',
                    'transaction_type' => 'PRODUCTION',
                    'reference_type' => 'ProductionOrder',
                    'reference_id' => $poId,
                    'reference_number' => $poNumber,
                    'notes' => "Hasil proses Moulding (S4S)",
                    'user_id' => Auth::id(),
                ]);

                $results[] = [
                    'item_number'         => $index + 1,
                    'source_inventory_id' => $sourceInventoryId,
                    'output_item_id'      => $outputItemId,
                    'input_qty_processed' => $inputQty,
                    'output_qty_planned'  => $outputQty,
                ];
            }

            Log::info('=== MOULDING END ===');

            return response()->json([
                'success' => true,
                'message' => 'Proses Moulding berhasil disimpan untuk ' . count($results) . ' item.',
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
        $pembahananWarehouseId = 4; // Gudang Pembahanan

        $query = Inventory::where('warehouse_id', $pembahananWarehouseId)
            ->where('qty', '>', 0)
            ->with(['item', 'warehouse']);

        if ($request->filled('po_id')) {
            $query->where('ref_po_id', $request->po_id);
        }

        $inventories = $query->get(['id', 'warehouse_id', 'item_id', 'qty', 'ref_po_id', 'ref_product_id']);

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

        Log::info('Source Inventories (Gudang Pembahanan):', $mapped->toArray());

        return response()->json([
            'success' => true,
            'data'    => $mapped,
        ]);
    }
}
