<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;

class InitialStockClaimService
{
    public function physicalByWarehouse(int $itemId): array
    {
        return Inventory::where('item_id', $itemId)
            ->where('qty_pcs', '>', 0)
            ->with('warehouse')
            ->get()
            ->groupBy('warehouse_id')
            ->map(fn ($rows) => [
                'warehouse_code' => $rows->first()->warehouse?->code ?? '-',
                'warehouse_name' => $rows->first()->warehouse?->name ?? '-',
                'qty'            => (float) $rows->sum('qty_pcs'),
            ])
            ->values()
            ->all();
    }

    public function activeClaims(int $itemId, ?int $excludeDetailId = null): array
    {
        return ProductionOrderDetail::where('item_id', $itemId)
            ->where('initial_stock_snapshot', '>', 0)
            ->when($excludeDetailId, fn ($q) => $q->where('id', '!=', $excludeDetailId))
            ->whereHas('productionOrder', fn ($q) => $q->whereNotIn('status', [
                ProductionOrder::STATUS_COMPLETED,
                ProductionOrder::STATUS_CANCELLED,
            ]))
            ->with('productionOrder.salesOrder')
            ->orderBy('id')
            ->get()
            ->map(fn ($d) => [
                'production_order_detail_id' => $d->id,
                'so_number'                  => $d->productionOrder?->salesOrder?->so_number ?? ($d->productionOrder?->po_number ?? '-'),
                'qty'                        => (float) $d->initial_stock_snapshot,
            ])
            ->all();
    }

    public function info(ProductionOrderDetail $detail): array
    {
        $physical = $this->physicalByWarehouse($detail->item_id);
        $claims = $this->activeClaims($detail->item_id, $detail->id);
        $physicalTotal = array_sum(array_column($physical, 'qty'));
        $claimedTotal = array_sum(array_column($claims, 'qty'));

        return [
            'current'        => (float) $detail->initial_stock_snapshot,
            'physical_total' => $physicalTotal,
            'physical'       => $physical,
            'claimed_total'  => $claimedTotal,
            'claims'         => $claims,
            'free'           => max(0, $physicalTotal - $claimedTotal),
        ];
    }
}
