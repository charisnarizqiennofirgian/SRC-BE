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
    /**
     * GET /finishing/source-warehouses
     */
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

    /**
     * GET /finishing/available-stock
     */
    public function getAvailableStock(Request $request)
    {
        $productionOrderId = $request->input('production_order_id');

        if (!$productionOrderId) {
            return response()->json([
                'success' => false,
                'message' => 'production_order_id diperlukan',
            ], 400);
        }

        $sourceWarehouseCode = $request->input('source_warehouse_code', 'RUSTIK');
        $warehouseSource = Warehouse::where('code', $sourceWarehouseCode)->firstOrFail();

        $po = ProductionOrder::with('details.item')->findOrFail($productionOrderId);

        $stocks = [];

        foreach ($po->details as $detail) {
            $inventory = Inventory::where('warehouse_id', $warehouseSource->id)
                ->where('item_id', $detail->item_id)
                ->first();

            $stocks[] = [
                'detail_id' => $detail->id,
                'item_id' => $detail->item_id,
                'item_name' => $detail->item->name,
                'qty_planned' => $detail->qty_planned,
                'qty_produced' => $detail->qty_produced,
                'stock_available' => $inventory ? $inventory->qty : 0,
                'source_warehouse_id' => $warehouseSource->id,
                'source_warehouse_name' => $warehouseSource->name,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $stocks,
            'source_warehouse' => [
                'id' => $warehouseSource->id,
                'code' => $warehouseSource->code,
                'name' => $warehouseSource->name,
            ],
        ]);
    }

    /**
     * POST /finishing/process
     */
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

        if (!empty($data['source_warehouse_id'])) {
            $warehouseSource = Warehouse::findOrFail($data['source_warehouse_id']);
        } else {
            $warehouseSource = Warehouse::where('code', 'RUSTIK')->firstOrFail();
        }

        $warehouseFinishing = Warehouse::where('code', 'FINISHING')->firstOrFail();

        DB::beginTransaction();

        try {
            $detail = ProductionOrderDetail::with('item', 'productionOrder')->findOrFail($data['production_order_detail_id']);
            $productionOrder = $detail->productionOrder;
            $qtyFinished = $data['qty_finished'];

            // STEP 1: Kurangi stok di Gudang Source
            $inventorySource = Inventory::where('warehouse_id', $warehouseSource->id)
                ->where('item_id', $detail->item_id)
                ->lockForUpdate()
                ->first();

            if (!$inventorySource || $inventorySource->qty < $qtyFinished) {
                throw new \Exception("Stok di {$warehouseSource->name} tidak cukup! (Tersedia: " . ($inventorySource ? $inventorySource->qty : 0) . ")");
            }

            $inventorySource->decrement('qty', $qtyFinished);

            // Catat ke inventory_logs (OUT dari gudang sumber)
            InventoryLog::create([
                'date' => now()->toDateString(),
                'time' => now()->toTimeString(),
                'item_id' => $detail->item_id,
                'warehouse_id' => $warehouseSource->id,
                'qty' => $qtyFinished,
                'direction' => 'OUT',
                'transaction_type' => 'TRANSFER_OUT',
                'reference_type' => 'ProductionOrder',
                'reference_id' => $productionOrder->id,
                'reference_number' => $productionOrder->po_number,
                'notes' => "Transfer ke Finishing dari {$warehouseSource->name}",
                'user_id' => Auth::id(),
            ]);

            // STEP 2: Tambah stok di Gudang Finishing
            $inventoryTarget = Inventory::firstOrCreate(
                [
                    'warehouse_id' => $warehouseFinishing->id,
                    'item_id' => $detail->item_id,
                ],
                [
                    'qty' => 0,
                    'qty_m3' => 0,
                ]
            );

            $inventoryTarget->increment('qty', $qtyFinished);

            // Catat ke inventory_logs (IN ke Gudang Finishing)
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

            // STEP 3: Catat di production log
            ProductionLog::create([
                'date' => now(),
                'reference_number' => $productionOrder->po_number,
                'process_type' => 'finishing',
                'stage' => 'finishing',
                'source_warehouse_id' => $warehouseSource->id,
                'destination_warehouse_id' => $warehouseFinishing->id,
                'input_item_id' => $detail->item_id,
                'input_quantity' => $qtyFinished,
                'output_item_id' => $detail->item_id,
                'output_quantity' => $qtyFinished,
                'notes' => "Finishing {$qtyFinished} pcs {$detail->item->name} dari {$warehouseSource->name}",
                'user_id' => Auth::id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => '✅ Proses Finishing berhasil! Barang sudah dipindahkan ke Gudang Finishing.',
                'data' => [
                    'qty_finished' => $qtyFinished,
                    'source_warehouse' => $warehouseSource->name,
                    'destination_warehouse' => $warehouseFinishing->name,
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
