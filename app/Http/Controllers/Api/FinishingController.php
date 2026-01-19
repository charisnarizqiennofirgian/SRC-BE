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

class FinishingController extends Controller
{
    public function getSourceWarehouses()
    {
        try {
            $warehouses = Warehouse::whereIn('code', ['ASSEMBLING', 'SANDING', 'RUSTIK'])
                ->get(['id', 'code', 'name']);

            return response()->json([
                'success' => true,
                'data' => $warehouses,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

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

        $sourcePriority = ['ASSEMBLING', 'SANDING', 'RUSTIK'];

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
            'qty_finished' => ['required', 'numeric', 'min:0.001'],
            'source_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $qtyFinished = $data['qty_finished'];

        DB::beginTransaction();

        try {
            $detail = ProductionOrderDetail::with('item', 'productionOrder')->findOrFail($data['production_order_detail_id']);
            $productionOrder = $detail->productionOrder;

            $sourcePriority = ['ASSEMBLING', 'SANDING', 'RUSTIK'];

            if (!empty($data['source_warehouse_id'])) {
                $specificWarehouse = Warehouse::find($data['source_warehouse_id']);
                if ($specificWarehouse) {
                    $sourcePriority = [$specificWarehouse->code];
                }
            }

            $warehouseFinishing = Warehouse::where('code', 'FINISHING')->firstOrFail();

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

            if ($totalAvailable < $qtyFinished) {
                throw new \Exception("Stok {$detail->item->name} tidak cukup! Butuh {$qtyFinished} pcs, tersedia {$totalAvailable} pcs.");
            }

            $remaining = $qtyFinished;
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
                    'notes' => "Transfer ke Finishing dari {$source['warehouse']->name}",
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

            $inventoryFinishing = Inventory::where('warehouse_id', $warehouseFinishing->id)
                ->where('item_id', $detail->item_id)
                ->where('ref_po_id', $productionOrder->id)
                ->first();

            if ($inventoryFinishing) {
                $inventoryFinishing->increment('qty', $qtyFinished);
            } else {
                Inventory::create([
                    'warehouse_id' => $warehouseFinishing->id,
                    'item_id' => $detail->item_id,
                    'qty' => $qtyFinished,
                    'qty_m3' => 0,
                    'ref_po_id' => $productionOrder->id,
                    'ref_product_id' => $detail->item_id,
                ]);
            }

            InventoryLog::create([
                'date' => now()->toDateString(),
                'time' => now()->toTimeString(),
                'item_id' => $detail->item_id,
                'warehouse_id' => $warehouseFinishing->id,
                'qty' => $qtyFinished,
                'direction' => 'IN',
                'transaction_type' => 'TRANSFER_IN',
                'reference_type' => 'ProductionOrder',
                'reference_id' => $productionOrder->id,
                'reference_number' => $productionOrder->po_number,
                'notes' => "Hasil proses Finishing",
                'user_id' => Auth::id(),
            ]);

            $usedSourcesArray = array_values($usedSources);
            $sourcesText = collect($usedSourcesArray)->map(function($s) {
                return "{$s['qty']} dari {$s['warehouse_name']}";
            })->implode(', ');

            ProductionLog::create([
                'date' => now(),
                'reference_number' => $productionOrder->po_number,
                'process_type' => 'finishing',
                'stage' => 'finishing',
                'source_warehouse_id' => $usedSourcesArray[0]['warehouse_id'] ?? null,
                'destination_warehouse_id' => $warehouseFinishing->id,
                'input_item_id' => $detail->item_id,
                'input_quantity' => $qtyFinished,
                'output_item_id' => $detail->item_id,
                'output_quantity' => $qtyFinished,
                'notes' => "Finishing {$qtyFinished} pcs {$detail->item->name} ({$sourcesText})",
                'user_id' => Auth::id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => '✅ Proses Finishing berhasil!',
                'data' => [
                    'qty_finished' => $qtyFinished,
                    'sources_used' => $usedSourcesArray,
                    'destination' => $warehouseFinishing->name,
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
