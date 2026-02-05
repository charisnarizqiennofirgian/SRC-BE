<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
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

    // LIST UNTUK DROPDOWN / GRID
    public function index(Request $request)
    {
        // Jika hanya ingin data sederhana untuk dropdown
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

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by status_not
        if ($request->filled('status_not')) {
            $query->where('status', '!=', $request->status_not);
        }

        // ✅ FILTER BY CURRENT STAGE (UNTUK MENU MOULDING, ASSEMBLY, DST)
        if ($request->filled('current_stage')) {
            $query->where('current_stage', $request->current_stage);
        }

        // ✅ FILTER: PO yang sudah siap untuk stage tertentu
        if ($request->filled('ready_for_stage')) {
            $targetStage = $request->ready_for_stage;

            // Contoh: ready_for_stage=moulding
            // Artinya: current_stage = 'pembahanan' (sebelum moulding)
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

        // Filter by buyer
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

            // ✅ FORMAT PANJANG UNTUK SEMUA MENU
            $label = $po->po_number;
            if ($buyerName) {
                $label .= ' - ' . $buyerName;
            }
            if ($soNumber) {
                $label .= ' - ' . $soNumber;
            }

            return [
                'id'             => $po->id,
                'po_number'      => $po->po_number,
                'label'          => $label,
                'status'         => $po->status,
                'current_stage'  => $po->current_stage,      // ✅ TAMBAH
                'skip_sawmill'   => $po->skip_sawmill,       // ✅ TAMBAH
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

    // Fungsi baru untuk mendapatkan data sederhana PO (untuk dropdown)
    public function simpleList()
    {
        $pos = ProductionOrder::select('id', 'po_number')->latest()->get();

        return response()->json([
            'success' => true,
            'data'    => $pos,
        ]);
    }

    // DETAIL UNTUK CONTEKAN DI SAWMILL
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
                'current_stage' => $productionOrder->current_stage,      // ✅ TAMBAH
                'skip_sawmill'  => $productionOrder->skip_sawmill,       // ✅ TAMBAH
                'sales_order_id'=> $productionOrder->sales_order_id,
                'sales_order'   => [
                    'so_number'  => $productionOrder->salesOrder?->so_number,
                    'buyer_name' => $productionOrder->salesOrder?->buyer?->name,
                ],
                'details' => $productionOrder->details->map(function ($d) {
                    return [
                        'id'           => $d->id,
                        'item_id'      => $d->item_id,
                        'item'         => [
                            'id'   => $d->item->id,
                            'name' => $d->item->name,
                            'code' => $d->item->code,
                        ],
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

    // ✅ BUAT DARI SALES ORDER (UPDATED WITH ROUTING)
    public function storeFromSalesOrder(Request $request, SalesOrder $salesOrder)
    {
        return DB::transaction(function () use ($request, $salesOrder) {
            $poNumber = 'PO-' . $salesOrder->id . '-' . now()->format('YmdHis');

            // Buat Production Order
            $productionOrder = ProductionOrder::create([
                'po_number'      => $poNumber,
                'sales_order_id' => $salesOrder->id,
                'status'         => 'released',                        // ✅ Langsung released
                'current_stage'  => ProductionOrder::STAGE_PENDING,    // ✅ Stage pending
                'skip_sawmill'   => false,                             // ✅ Default false
                'notes'          => $request->input('notes'),
                'created_by'     => $request->user()->id,
            ]);

            // Buat detail
            foreach ($salesOrder->details as $detail) {
                ProductionOrderDetail::create([
                    'production_order_id'   => $productionOrder->id,
                    'sales_order_detail_id' => $detail->id,
                    'item_id'               => $detail->item_id,
                    'qty_planned'           => $detail->quantity,
                    'qty_produced'          => 0,
                ]);
            }

            // Load relations untuk routing
            $productionOrder->load('details.item');

            // ✅ TENTUKAN ROUTING (CEK STOK RST)
            $routing = $this->routingService->determineRouting($productionOrder);

            // Update PO dengan routing result
            $productionOrder->update([
                'skip_sawmill' => $routing['skip_sawmill'],
                'current_stage' => $routing['next_stage'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Production Order berhasil dibuat dari Sales Order.',
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
}
