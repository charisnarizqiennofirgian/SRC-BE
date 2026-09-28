<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\MesinProduction;
use App\Models\MouldingProduction;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\RustikKomponenProduction;
use App\Models\SalesOrder;

class ProductionOrderDetailSyncService
{
    public function sync(ProductionOrder $productionOrder, SalesOrder $salesOrder): array
    {
        $existingByItem = $productionOrder->details()->get()->groupBy('item_id');
        $soDetails = $salesOrder->details()->get();

        $result = [
            'repointed' => 0,
            'added'     => [],
            'removed'   => [],
            'blocked'   => [],
        ];

        foreach ($soDetails as $detail) {
            $existingMatch = null;
            if (!empty($existingByItem[$detail->item_id])) {
                $existingMatch = $existingByItem[$detail->item_id]->shift();
            }

            if ($existingMatch) {
                if ((int) $existingMatch->sales_order_detail_id !== (int) $detail->id) {
                    $existingMatch->update(['sales_order_detail_id' => $detail->id]);
                    $result['repointed']++;
                }
                continue;
            }

            $newDetail = ProductionOrderDetail::create([
                'production_order_id'    => $productionOrder->id,
                'sales_order_detail_id'  => $detail->id,
                'item_id'                => $detail->item_id,
                'qty_planned'            => $detail->quantity,
                'qty_produced'           => 0,
                'initial_stock_snapshot' => Inventory::getAvailableFinishedStock($detail->item_id),
            ]);
            $result['added'][] = $newDetail;
        }

        foreach ($existingByItem as $remaining) {
            foreach ($remaining as $orphan) {
                if ($this->hasActivity($orphan)) {
                    $result['blocked'][] = $orphan;
                    continue;
                }

                $orphan->delete();
                $result['removed'][] = $orphan;
            }
        }

        return $result;
    }

    public function hasActivity(ProductionOrderDetail $detail): bool
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
}
