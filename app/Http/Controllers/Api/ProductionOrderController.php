<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionOrderController extends Controller
{
    // LIST UNTUK DROPDOWN / GRID
    public function index(Request $request)
    {
        $query = ProductionOrder::query()
            ->with([
                'salesOrder.buyer',
                'details.item', // untuk ambil nama produk utama
            ])
            ->orderByDesc('created_at');

        // ?status=...
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // ?status_not=completed
        if ($request->filled('status_not')) {
            $query->where('status', '!=', $request->status_not);
        }

        // ?buyer_id=...
        if ($request->filled('buyer_id')) {
            $query->whereHas('salesOrder', function ($q) use ($request) {
                $q->where('buyer_id', $request->buyer_id);
            });
        }

        $productionOrders = $query->get();

        $data = $productionOrders->map(function ($po) {
            $buyerName = $po->salesOrder?->buyer?->name;
            $soNumber  = $po->salesOrder?->so_number;

            // ambil satu produk utama dari detail PO (kalau ada)
            $mainTarget = $po->details->first();
            $productName = $mainTarget?->item?->name ?? null;

            return [
                'id'             => $po->id,
                'po_number'      => $po->po_number,
                'label'          => trim(sprintf(
                    '%s - %s - %s',
                    $po->po_number,
                    $buyerName ?: '-',
                    $soNumber ?: '-'
                ), ' -'),
                'status'         => $po->status,
                'sales_order_id' => $po->sales_order_id,
                'buyer_name'     => $buyerName,
                'product_name'   => $productName,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    // DETAIL UNTUK CONTEKAN DI SAWMILL
    public function show(ProductionOrder $productionOrder)
    {
        $productionOrder->load([
            'salesOrder.buyer',
            'details.item',
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'            => $productionOrder->id,
                'po_number'     => $productionOrder->po_number,
                'status'        => $productionOrder->status,
                'sales_order_id'=> $productionOrder->sales_order_id,
                'sales_order'   => [
                    'so_number'  => $productionOrder->salesOrder?->so_number,
                    'buyer_name' => $productionOrder->salesOrder?->buyer?->name,
                ],
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

    // BUAT DARI SALES ORDER
    public function storeFromSalesOrder(Request $request, SalesOrder $salesOrder)
    {
        return DB::transaction(function () use ($request, $salesOrder) {
            $poNumber = 'PO-' . $salesOrder->id . '-' . now()->format('YmdHis');

            $productionOrder = ProductionOrder::create([
                'po_number'      => $poNumber,
                'sales_order_id' => $salesOrder->id,
                'status'         => 'draft',
                'notes'          => $request->input('notes'),
                'created_by'     => $request->user()->id,
            ]);

            foreach ($salesOrder->details as $detail) {
                ProductionOrderDetail::create([
                    'production_order_id'   => $productionOrder->id,
                    'sales_order_detail_id' => $detail->id,
                    'item_id'               => $detail->item_id,
                    'qty_planned'           => $detail->quantity,
                    'qty_produced'          => 0,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Production Order berhasil dibuat dari Sales Order.',
                'data'    => $productionOrder->load('details'),
            ]);
        });
    }
}
