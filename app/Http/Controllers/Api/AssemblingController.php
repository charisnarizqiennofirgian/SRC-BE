<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\Inventory;
use App\Models\Warehouse;
use App\Models\Category;
use App\Models\ProductionLog;
use App\Models\InventoryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class AssemblingController extends Controller
{

    public function getAvailableOrders()
    {
        $orders = ProductionOrder::with(['details.item', 'salesOrder.buyer'])
            ->where('status', '!=', 'completed')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($po) {
                $buyerName = $po->salesOrder?->buyer?->name;
                $soNumber = $po->salesOrder?->so_number;

                $label = $po->po_number;
                if ($buyerName) {
                    $label .= ' - ' . $buyerName;
                }
                if ($soNumber) {
                    $label .= ' - ' . $soNumber;
                }

                return [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'label' => $label,
                    'status' => $po->status,
                    'buyer_name' => $buyerName,
                    'so_number' => $soNumber,
                    'details' => $po->details->map(function ($detail) {
                        return [
                            'id' => $detail->id,
                            'item_id' => $detail->item_id,
                            'item' => [
                                'id' => $detail->item->id,
                                'name' => $detail->item->name,
                                'code' => $detail->item->code,
                            ],
                            'qty_planned' => $detail->qty_planned,
                            'qty_produced' => $detail->qty_produced,
                        ];
                    }),
                ];
            });

        return response()->json($orders);
    }


    public function checkMaterialAvailability(Request $request)
    {
        $detailId = $request->detail_id;

        $detail = ProductionOrderDetail::with('item.bomComponents.childItem')->findOrFail($detailId);

        $warehouseKomponen = Warehouse::where('code', 'MESIN')->first();

        if (!$warehouseKomponen) {
            return response()->json(['error' => 'Gudang Komponen tidak ditemukan'], 404);
        }

        $components = [];
        $maxCanProduce = PHP_INT_MAX;

        foreach ($detail->item->bomComponents as $bom) {
            $stockAvailable = Inventory::where('warehouse_id', $warehouseKomponen->id)
                ->where('item_id', $bom->child_item_id)
                ->sum('qty');

            $qtyNeeded = $bom->qty;

            $canProduce = $qtyNeeded > 0 ? floor($stockAvailable / $qtyNeeded) : 0;

            $maxCanProduce = min($maxCanProduce, $canProduce);

            $components[] = [
                'component_id' => $bom->child_item_id,
                'component_name' => $bom->childItem->name,
                'qty_needed_per_unit' => $qtyNeeded,
                'stock_available' => $stockAvailable,
                'can_produce' => $canProduce,
                'is_sufficient' => $stockAvailable >= $qtyNeeded,
            ];
        }

        return response()->json([
            'components' => $components,
            'max_can_produce' => $maxCanProduce == PHP_INT_MAX ? 0 : $maxCanProduce,
            'detail' => [
                'item_name' => $detail->item->name,
                'qty_remaining' => $detail->qty_planned - $detail->qty_produced,
            ]
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'production_order_id' => 'required|exists:production_orders,id',
            'products' => 'required|array|min:1',
            'products.*.detail_id' => 'required|exists:production_order_details,id',
            'products.*.qty_good' => 'required|numeric|min:1',
            'used_components' => 'required|array|min:1',
            'used_components.*.item_id' => 'required|exists:items,id',
            'used_components.*.qty' => 'required|numeric|min:0.001',
            'rejected_components' => 'nullable|array',
            'rejected_components.*.item_id' => 'required_with:rejected_components|exists:items,id',
            'rejected_components.*.qty' => 'required_with:rejected_components|numeric|min:0.001',
        ]);

        DB::beginTransaction();

        try {
            $warehouseKomponen = Warehouse::where('code', 'MESIN')->firstOrFail();
            $warehouseAssembling = Warehouse::where('code', 'ASSEMBLING')->firstOrFail();

            $productionOrder = ProductionOrder::findOrFail($request->production_order_id);

            foreach ($request->used_components as $comp) {
                $stockAvailable = Inventory::where('warehouse_id', $warehouseKomponen->id)
                    ->where('item_id', $comp['item_id'])
                    ->sum('qty');

                if ($stockAvailable < $comp['qty']) {
                    $item = \App\Models\Item::find($comp['item_id']);
                    throw new \Exception("Stok Komponen {$item->name} tidak cukup! Butuh {$comp['qty']}, tersedia {$stockAvailable}");
                }
            }

            foreach ($request->used_components as $comp) {
                $qtyToDeduct = $comp['qty'];

                $inventories = Inventory::where('warehouse_id', $warehouseKomponen->id)
                    ->where('item_id', $comp['item_id'])
                    ->where('qty', '>', 0)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($inventories as $inventory) {
                    if ($qtyToDeduct <= 0) break;

                    $qtyTaken = min($qtyToDeduct, $inventory->qty);
                    $inventory->decrement('qty', $qtyTaken);
                    $qtyToDeduct -= $qtyTaken;
                }

                InventoryLog::create([
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'item_id' => $comp['item_id'],
                    'warehouse_id' => $warehouseKomponen->id,
                    'qty' => $comp['qty'],
                    'direction' => 'OUT',
                    'transaction_type' => 'PRODUCTION',
                    'reference_type' => 'ProductionOrder',
                    'reference_id' => $productionOrder->id,
                    'reference_number' => $productionOrder->po_number,
                    'notes' => "Komponen dipakai untuk assembling",
                    'user_id' => Auth::id(),
                ]);
            }

            if ($request->rejected_components) {
                foreach ($request->rejected_components as $reject) {
                    $qtyToDeduct = $reject['qty'];

                    $inventories = Inventory::where('warehouse_id', $warehouseKomponen->id)
                        ->where('item_id', $reject['item_id'])
                        ->where('qty', '>', 0)
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get();

                    foreach ($inventories as $inventory) {
                        if ($qtyToDeduct <= 0) break;

                        $qtyTaken = min($qtyToDeduct, $inventory->qty);
                        $inventory->decrement('qty', $qtyTaken);
                        $qtyToDeduct -= $qtyTaken;
                    }

                    InventoryLog::create([
                        'date' => now()->toDateString(),
                        'time' => now()->toTimeString(),
                        'item_id' => $reject['item_id'],
                        'warehouse_id' => $warehouseKomponen->id,
                        'qty' => $reject['qty'],
                        'direction' => 'OUT',
                        'transaction_type' => 'ADJUSTMENT',
                        'reference_type' => 'ProductionOrder',
                        'reference_id' => $productionOrder->id,
                        'reference_number' => $productionOrder->po_number,
                        'notes' => "Komponen reject/rusak saat assembling",
                        'user_id' => Auth::id(),
                    ]);
                }
            }

            foreach ($request->products as $product) {
                $detail = ProductionOrderDetail::with('item')->findOrFail($product['detail_id']);
                $qtyGood = $product['qty_good'];

                $inventoryWhiteBody = Inventory::where('warehouse_id', $warehouseAssembling->id)
                    ->where('item_id', $detail->item_id)
                    ->where('ref_po_id', $productionOrder->id)
                    ->first();

                if ($inventoryWhiteBody) {
                    $inventoryWhiteBody->increment('qty', $qtyGood);
                } else {
                    Inventory::create([
                        'warehouse_id' => $warehouseAssembling->id,
                        'item_id' => $detail->item_id,
                        'qty' => $qtyGood,
                        'qty_m3' => 0,
                        'ref_po_id' => $productionOrder->id,
                        'ref_product_id' => $detail->item_id,
                    ]);
                }

                InventoryLog::create([
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'item_id' => $detail->item_id,
                    'warehouse_id' => $warehouseAssembling->id,
                    'qty' => $qtyGood,
                    'direction' => 'IN',
                    'transaction_type' => 'PRODUCTION',
                    'reference_type' => 'ProductionOrder',
                    'reference_id' => $productionOrder->id,
                    'reference_number' => $productionOrder->po_number,
                    'notes' => "Hasil assembling {$detail->item->name}",
                    'user_id' => Auth::id(),
                ]);

                $detail->increment('qty_produced', $qtyGood);

                ProductionLog::create([
                    'date' => now(),
                    'reference_number' => $detail->productionOrder->po_number,
                    'process_type' => 'assembling',
                    'stage' => 'assembling',
                    'input_item_id' => null,
                    'input_quantity' => array_sum(array_column($request->used_components, 'qty')),
                    'output_item_id' => $detail->item_id,
                    'output_quantity' => $qtyGood,
                    'notes' => "Assembling {$qtyGood} pcs {$detail->item->name}",
                    'user_id' => Auth::id(),
                ]);
            }

            $po = ProductionOrder::with('details')->findOrFail($request->production_order_id);
            $allCompleted = $po->details->every(function ($d) {
                return $d->qty_produced >= $d->qty_planned;
            });

            if ($allCompleted) {
                $po->update(['status' => 'completed_assembling']);
            }

            DB::commit();

            return response()->json([
                'message' => 'Proses assembling berhasil!',
                'products_assembled' => count($request->products),
                'total_qty' => array_sum(array_column($request->products, 'qty_good')),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
