<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\ProductionLog;
use App\Models\Inventory;
use App\Models\Warehouse;
use App\Models\InventoryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PackingController extends Controller
{
    public function getAvailableStock(Request $request)
    {
        $productionOrderId = $request->input('production_order_id');

        if (!$productionOrderId) {
            return response()->json([
                'success' => false,
                'message' => 'production_order_id diperlukan',
            ], 400);
        }

        $po = ProductionOrder::with('details.item')->findOrFail($productionOrderId);

        $sourcePriority = ['FINISHING', 'RUSTIK', 'SANDING', 'ASSEMBLING'];

        $stocks = [];

        foreach ($po->details as $detail) {
            $totalAvailable = 0;
            $sourceDetails = [];

            foreach ($sourcePriority as $warehouseCode) {
                $warehouse = Warehouse::where('code', $warehouseCode)->first();
                if (!$warehouse) continue;

                $available = Inventory::where('warehouse_id', $warehouse->id)
                    ->where('item_id', $detail->item_id)
                    ->sum('qty');

                $sourceDetails[] = [
                    'warehouse_id' => $warehouse->id,
                    'warehouse_code' => $warehouse->code,
                    'warehouse_name' => $warehouse->name,
                    'stock_available' => $available,
                ];

                $totalAvailable += $available;
            }

            $stocks[] = [
                'detail_id' => $detail->id,
                'item_id' => $detail->item_id,
                'item_name' => $detail->item->name,
                'qty_planned' => $detail->qty_planned,
                'qty_produced' => $detail->qty_produced,
                'total_stock_available' => $totalAvailable,
                'source_details' => $sourceDetails,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $stocks,
        ]);
    }

    public function process(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'production_order_detail_id' => ['required', 'integer', 'exists:production_order_details,id'],
            'qty_packed' => ['required', 'numeric', 'min:0.001'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $qtyPacked = $data['qty_packed'];

        DB::beginTransaction();

        try {
            $detail = ProductionOrderDetail::with('item', 'productionOrder')->findOrFail($data['production_order_detail_id']);
            $productionOrder = $detail->productionOrder;

            $sourcePriority = ['FINISHING', 'RUSTIK', 'SANDING', 'ASSEMBLING'];

            $warehousePacking = Warehouse::where('code', 'PACKING')->firstOrFail();

            $totalAvailable = 0;
            $stockSources = [];

            foreach ($sourcePriority as $warehouseCode) {
                $warehouse = Warehouse::where('code', $warehouseCode)->first();
                if (!$warehouse) continue;

                $inventories = Inventory::where('warehouse_id', $warehouse->id)
                    ->where('item_id', $detail->item_id)
                    ->where('qty', '>', 0)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($inventories as $inventory) {
                    if ($inventory->qty > 0) {
                        $stockSources[] = [
                            'warehouse' => $warehouse,
                            'inventory' => $inventory,
                            'available' => $inventory->qty,
                        ];
                        $totalAvailable += $inventory->qty;
                    }
                }
            }

            if ($totalAvailable < $qtyPacked) {
                throw new \Exception("Stok {$detail->item->name} tidak cukup! Butuh {$qtyPacked} pcs, tersedia {$totalAvailable} pcs.");
            }

            $remaining = $qtyPacked;
            $usedSources = [];

            foreach ($stockSources as $source) {
                if ($remaining <= 0) break;

                $toTake = min($remaining, $source['available']);

                $source['inventory']->decrement('qty', $toTake);

                InventoryLog::create([
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'item_id' => $detail->item_id,
                    'warehouse_id' => $source['warehouse']->id,
                    'qty' => $toTake,
                    'direction' => 'OUT',
                    'transaction_type' => 'TRANSFER_OUT',
                    'reference_type' => 'ProductionOrder',
                    'reference_id' => $productionOrder->id,
                    'reference_number' => $productionOrder->po_number,
                    'notes' => "Transfer ke Packing dari {$source['warehouse']->name}",
                    'user_id' => Auth::id(),
                ]);

                if (!isset($usedSources[$source['warehouse']->id])) {
                    $usedSources[$source['warehouse']->id] = [
                        'warehouse_id' => $source['warehouse']->id,
                        'warehouse_name' => $source['warehouse']->name,
                        'qty' => 0,
                    ];
                }
                $usedSources[$source['warehouse']->id]['qty'] += $toTake;

                $remaining -= $toTake;
            }

            $inventoryPacking = Inventory::where('warehouse_id', $warehousePacking->id)
                ->where('item_id', $detail->item_id)
                ->where('ref_po_id', $productionOrder->id)
                ->first();

            if ($inventoryPacking) {
                $inventoryPacking->increment('qty', $qtyPacked);
            } else {
                Inventory::create([
                    'warehouse_id' => $warehousePacking->id,
                    'item_id' => $detail->item_id,
                    'qty' => $qtyPacked,
                    'qty_m3' => 0,
                    'ref_po_id' => $productionOrder->id,
                    'ref_product_id' => $detail->item_id,
                ]);
            }

            InventoryLog::create([
                'date' => now()->toDateString(),
                'time' => now()->toTimeString(),
                'item_id' => $detail->item_id,
                'warehouse_id' => $warehousePacking->id,
                'qty' => $qtyPacked,
                'direction' => 'IN',
                'transaction_type' => 'TRANSFER_IN',
                'reference_type' => 'ProductionOrder',
                'reference_id' => $productionOrder->id,
                'reference_number' => $productionOrder->po_number,
                'notes' => "Hasil proses Packing (Barang Jadi)",
                'user_id' => Auth::id(),
            ]);

            $usedSourcesArray = array_values($usedSources);
            $sourcesText = collect($usedSourcesArray)->map(function($s) {
                return "{$s['qty']} dari {$s['warehouse_name']}";
            })->implode(', ');

            ProductionLog::create([
                'date' => now(),
                'reference_number' => $productionOrder->po_number,
                'process_type' => 'packing',
                'stage' => 'packing',
                'source_warehouse_id' => $usedSourcesArray[0]['warehouse_id'] ?? null,
                'destination_warehouse_id' => $warehousePacking->id,
                'input_item_id' => $detail->item_id,
                'input_quantity' => $qtyPacked,
                'output_item_id' => $detail->item_id,
                'output_quantity' => $qtyPacked,
                'notes' => "Packing {$qtyPacked} pcs {$detail->item->name} ({$sourcesText})",
                'user_id' => Auth::id(),
            ]);

            $allCompleted = $productionOrder->details->every(function ($d) use ($warehousePacking) {
                $stockPacking = Inventory::where('warehouse_id', $warehousePacking->id)
                    ->where('item_id', $d->item_id)
                    ->sum('qty');

                return $stockPacking >= $d->qty_planned;
            });

            if ($allCompleted) {
                $productionOrder->update(['status' => 'completed']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => '✅ Proses Packing berhasil!',
                'data' => [
                    'qty_packed' => $qtyPacked,
                    'sources_used' => $usedSourcesArray,
                    'destination' => $warehousePacking->name,
                    'po_completed' => $allCompleted,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
