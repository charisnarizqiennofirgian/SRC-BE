<?php

namespace App\Services;

use App\Models\ProductionOrder;

class ProductionOrderProgressService
{
    /**
     * Mark PO as on progress (dipanggil saat Sawmill mulai proses)
     * Update current_stage ke pembahanan (karena sawmill sudah selesai)
     */
    public function markOnProgress(int $productionOrderId): void
    {
        $po = ProductionOrder::find($productionOrderId);
        if (! $po) {
            return;
        }

        // Kalau masih draft, release dulu
        if ($po->status === 'draft') {
            $po->status = 'released';
        }

        // Update current_stage ke pembahanan (karena sawmill sudah selesai)
        // Artinya: PO sudah siap untuk proses berikutnya (Moulding)
        if ($po->current_stage === ProductionOrder::STAGE_SAWMILL
            || $po->current_stage === ProductionOrder::STAGE_PENDING) {
            $po->current_stage = ProductionOrder::STAGE_PEMBAHANAN;
        }

        $po->save();
    }

    /**
     * Move PO to next stage
     */
    public function moveToNextStage(int $productionOrderId): void
    {
        $po = ProductionOrder::find($productionOrderId);
        if (! $po) {
            return;
        }

        $nextStage = $po->moveToNextStage(); // Method dari model

        $po->current_stage = $nextStage;

        // Kalau sudah completed, update status
        if ($nextStage === ProductionOrder::STAGE_COMPLETED) {
            $po->status = ProductionOrder::STATUS_COMPLETED;
        }

        $po->save();
    }
}
