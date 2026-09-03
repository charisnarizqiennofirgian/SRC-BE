<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\MesinProduction;
use App\Models\MouldingProduction;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\RustikKomponenProduction;
use App\Models\SalesOrder;
use App\Services\ProductionRoutingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionOrderController extends Controller
{
    protected $routingService;

    public function __construct(ProductionRoutingService $routingService)
    {
        $this->routingService = $routingService;
    }

    public function index(Request $request)
    {
        if ($request->has('simple')) {
            $pos = ProductionOrder::select('id', 'po_number')->latest()->get();

            return response()->json([
                'success' => true,
                'data'    => $pos,
            ]);
        }

        $query = ProductionOrder::query()
            ->with([
                'salesOrder.buyer',
                'details.item',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('status_not')) {
            $query->where('status', '!=', $request->status_not);
        }

        if ($request->filled('for_sawmill')) {
            $query->whereIn('current_stage', [
                ProductionOrder::STAGE_PENDING,
                ProductionOrder::STAGE_SAWMILL,
            ]);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('current_stage')) {
            $query->where('current_stage', $request->current_stage);
        }

        if ($request->filled('ready_for_stage')) {
            $targetStage = $request->ready_for_stage;

            $stageMap = [
                'sawmill' => ProductionOrder::STAGE_PENDING,
                'pembahanan' => ProductionOrder::STAGE_SAWMILL,
                'moulding' => ProductionOrder::STAGE_PEMBAHANAN,
                'assembly' => ProductionOrder::STAGE_MOULDING,
                'finishing' => ProductionOrder::STAGE_ASSEMBLY,
                'packing' => ProductionOrder::STAGE_FINISHING,
            ];

            if (isset($stageMap[$targetStage])) {
                $query->where('current_stage', $stageMap[$targetStage]);
            }
        }

        if ($request->filled('buyer_id')) {
            $query->whereHas('salesOrder', function ($q) use ($request) {
                $q->where('buyer_id', $request->buyer_id);
            });
        }

        $productionOrders = $query->get();

        $data = $productionOrders->map(function ($po) {
            $buyerName = $po->salesOrder?->buyer?->name;
            $soNumber  = $po->salesOrder?->so_number;
            $mainTarget = $po->details->first();
            $productName = $mainTarget?->item?->name ?? null;

            $label = $po->po_number;

            return [
                'id'             => $po->id,
                'po_number'      => $po->po_number,
                'label'          => $label,
                'status'         => $po->status,
                'current_stage'  => $po->current_stage,
                'skip_sawmill'   => $po->skip_sawmill,
                'sales_order_id' => $po->sales_order_id,
                'buyer_name'     => $buyerName,
                'so_number'      => $soNumber,
                'product_name'   => $productName,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    public function simpleList()
    {
        $pos = ProductionOrder::select('id', 'po_number')->latest()->get();

        return response()->json([
            'success' => true,
            'data'    => $pos,
        ]);
    }

    public function show(ProductionOrder $productionOrder)
    {
        $productionOrder->load([
            'salesOrder.buyer',
            'details.item.unit',
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'            => $productionOrder->id,
                'po_number'     => $productionOrder->po_number,
                'status'        => $productionOrder->status,
                'current_stage' => $productionOrder->current_stage,
                'skip_sawmill'  => $productionOrder->skip_sawmill,
                'sales_order_id'=> $productionOrder->sales_order_id,
                'sales_order'   => [
                    'so_number'  => $productionOrder->salesOrder?->so_number,
                    'buyer_name' => $productionOrder->salesOrder?->buyer?->name,
                ],
                'details' => $productionOrder->details->map(function ($d) {
                    return [
                        'id'           => $d->id,
                        'item_id'      => $d->item_id,
                        'item'         => $d->item ? [
                            'id'   => $d->item->id,
                            'name' => $d->item->name,
                            'code' => $d->item->code,
                        ] : null,
                        'qty_planned'  => $d->qty_planned,
                        'qty_produced' => $d->qty_produced,
                    ];
                })->values(),
                'targets' => $productionOrder->details->map(function ($d) {
                    return [
                        'item_id'     => $d->item_id,
                        'code'        => $d->item?->code,
                        'name'        => $d->item?->name,
                        'qty_planned' => $d->qty_planned,
                    ];
                })->values(),
            ],
        ]);
    }

    public function storeFromSalesOrder(Request $request, SalesOrder $salesOrder)
    {
        return DB::transaction(function () use ($request, $salesOrder) {
            $salesOrder->load('buyer');

            $productionOrder = ProductionOrder::where('sales_order_id', $salesOrder->id)
                ->where(function ($q) {
                    $q->whereNull('type')->orWhere('type', '!=', 'sample');
                })
                ->first();

            $isNew = false;

            if (!$productionOrder) {
                $isNew = true;
                $poNumber = 'Produksi - ' . ($salesOrder->buyer->name ?? 'Unknown') . ' - ' . $salesOrder->so_number;

                $productionOrder = ProductionOrder::create([
                    'po_number'      => $poNumber,
                    'sales_order_id' => $salesOrder->id,
                    'status'         => 'released',
                    'current_stage'  => ProductionOrder::STAGE_PENDING,
                    'skip_sawmill'   => false,
                    'notes'          => $request->input('notes'),
                    'created_by'     => $request->user()->id,
                ]);
            }

            $existingByItem = $productionOrder->details()->get()->groupBy('item_id');

            $addedCount = 0;
            foreach ($salesOrder->details as $detail) {
                $existingMatch = null;
                if (!empty($existingByItem[$detail->item_id])) {
                    $existingMatch = $existingByItem[$detail->item_id]->shift();
                }

                if ($existingMatch) {
                    if ($existingMatch->sales_order_detail_id !== $detail->id) {
                        $existingMatch->update(['sales_order_detail_id' => $detail->id]);
                    }
                    continue;
                }

                ProductionOrderDetail::create([
                    'production_order_id'   => $productionOrder->id,
                    'sales_order_detail_id' => $detail->id,
                    'item_id'               => $detail->item_id,
                    'qty_planned'           => $detail->quantity,
                    'qty_produced'          => 0,
                    'initial_stock_snapshot' => Inventory::getAvailableFinishedStock($detail->item_id),
                ]);
                $addedCount++;
            }

            [$removedCount, $blockedCount] = $this->removeOrphanedProductionOrderDetails($existingByItem);

            $productionOrder->load('details.item');

            $routing = $this->routingService->determineRouting($productionOrder);

            $productionOrder->update([
                'skip_sawmill' => $routing['skip_sawmill'],
                'current_stage' => $routing['next_stage'],
            ]);

            $message = $isNew
                ? 'Production Order berhasil dibuat dari Sales Order.'
                : $this->buildSyncMessage('PO Produksi', $productionOrder->po_number, $addedCount, $removedCount, $blockedCount);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data'    => $productionOrder->load('details'),
                'routing' => [
                    'next_stage' => $routing['next_stage'],
                    'skip_sawmill' => $routing['skip_sawmill'],
                    'notes' => $routing['needs_sawmill']
                        ? 'PO harus lewat Sawmill terlebih dahulu'
                        : 'PO bisa langsung ke Pembahanan (RST tersedia)',
                    'missing_items' => $routing['missing_items'],
                ],
            ]);
        });
    }

    public function storeFromSalesOrderSample(Request $request, SalesOrder $salesOrder)
    {
        return DB::transaction(function () use ($request, $salesOrder) {
            $salesOrder->load('buyer');

            $productionOrder = ProductionOrder::where('sales_order_id', $salesOrder->id)
                ->where('type', 'sample')
                ->first();

            $isNew = false;

            if (!$productionOrder) {
                $isNew = true;
                $poNumber = 'Sampel - ' . ($salesOrder->buyer->name ?? 'Unknown') . ' - ' . $salesOrder->so_number;

                $productionOrder = ProductionOrder::create([
                    'po_number'      => $poNumber,
                    'sales_order_id' => $salesOrder->id,
                    'status'         => 'released',
                    'current_stage'  => ProductionOrder::STAGE_PENDING,
                    'skip_sawmill'   => false,
                    'type'           => 'sample',
                    'notes'          => $request->input('notes'),
                    'created_by'     => $request->user()->id,
                ]);
            }

            $existingByItem = $productionOrder->details()->get()->groupBy('item_id');

            $addedCount = 0;
            foreach ($salesOrder->details as $detail) {
                $existingMatch = null;
                if (!empty($existingByItem[$detail->item_id])) {
                    $existingMatch = $existingByItem[$detail->item_id]->shift();
                }

                if ($existingMatch) {
                    if ($existingMatch->sales_order_detail_id !== $detail->id) {
                        $existingMatch->update(['sales_order_detail_id' => $detail->id]);
                    }
                    continue;
                }

                ProductionOrderDetail::create([
                    'production_order_id'   => $productionOrder->id,
                    'sales_order_detail_id' => $detail->id,
                    'item_id'               => $detail->item_id,
                    'qty_planned'           => $detail->quantity,
                    'qty_produced'          => 0,
                    'initial_stock_snapshot' => Inventory::getAvailableFinishedStock($detail->item_id),
                ]);
                $addedCount++;
            }

            [$removedCount, $blockedCount] = $this->removeOrphanedProductionOrderDetails($existingByItem);

            $message = $isNew
                ? 'Production Order Sampel berhasil dibuat.'
                : $this->buildSyncMessage('PO Sampel', $productionOrder->po_number, $addedCount, $removedCount, $blockedCount);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data'    => $productionOrder->load('details'),
            ]);
        });
    }

    private function removeOrphanedProductionOrderDetails($existingByItem): array
    {
        $removedCount = 0;
        $blockedCount = 0;

        foreach ($existingByItem as $remaining) {
            foreach ($remaining as $orphan) {
                if ($this->productionDetailHasActivity($orphan)) {
                    $blockedCount++;
                    continue;
                }

                $orphan->delete();
                $removedCount++;
            }
        }

        return [$removedCount, $blockedCount];
    }

    private function productionDetailHasActivity(ProductionOrderDetail $detail): bool
    {
        if (!empty($detail->current_stage)) {
            return true;
        }

        if ((float) $detail->qty_produced > 0) {
            return true;
        }

        return MouldingProduction::where('production_order_detail_id', $detail->id)->exists()
            || MesinProduction::where('production_order_detail_id', $detail->id)->exists()
            || RustikKomponenProduction::where('production_order_detail_id', $detail->id)->exists();
    }

    private function buildSyncMessage(string $label, string $poNumber, int $addedCount, int $removedCount, int $blockedCount): string
    {
        $parts = [];
        if ($addedCount > 0) {
            $parts[] = "{$addedCount} item baru ditambahkan";
        }
        if ($removedCount > 0) {
            $parts[] = "{$removedCount} item yang sudah tidak ada di SO dihapus dari PO";
        }
        if ($blockedCount > 0) {
            $parts[] = "{$blockedCount} item sudah tidak ada di SO tapi TIDAK dihapus karena sudah ada progres produksi (cek manual)";
        }

        if (empty($parts)) {
            return "{$label} \"{$poNumber}\" sudah ada dan semua item sudah sinkron.";
        }

        return "{$label} \"{$poNumber}\" sudah ada, " . implode('; ', $parts) . '.';
    }
}
