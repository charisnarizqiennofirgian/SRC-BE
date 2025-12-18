<?php

namespace App\Services;

use App\Models\ProductionOrder;

class ProductionOrderProgressService
{
    public function markOnProgress(int $productionOrderId): void
    {
        $po = ProductionOrder::find($productionOrderId);
        if (! $po) {
            return;
        }

        if ($po->status === 'draft') {
            $po->status = 'on_progress';
            $po->save();
        }
    }
}
