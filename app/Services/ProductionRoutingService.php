<?php

namespace App\Services;

use App\Models\ProductionOrder;
use App\Models\Inventory;

class ProductionRoutingService
{
    /**
     * Warehouse ID untuk Gudang Pembahanan (Buffer RST)
     */
    const PEMBAHANAN_WAREHOUSE_ID = 4;

    /**
     * Tentukan routing: Butuh Sawmill atau bisa langsung Pembahanan?
     *
     * @param ProductionOrder $po
     * @return array
     */
    public function determineRouting(ProductionOrder $po): array
    {
        $needsSawmill = false;
        $missingItems = [];

        foreach ($po->details as $detail) {
            $item = $detail->item;

            // Cek 1: Apakah item HARUS lewat sawmill (dari log)?
            if ($item->needsSawmill()) {
                $needsSawmill = true;
                $missingItems[] = [
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'item_code' => $item->code,
                    'qty_needed' => $detail->qty_planned,
                    'reason' => 'Item harus diproses dari log (production_route = from_log)',
                ];
                continue; // Skip cek stok kalau memang harus dari log
            }

            // Cek 2: Apakah stok RST di Pembahanan cukup?
            $rstStock = $this->checkRstStock($item->id, $detail->qty_planned);

            if (!$rstStock['available']) {
                $needsSawmill = true;
                $missingItems[] = [
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'item_code' => $item->code,
                    'qty_needed' => $detail->qty_planned,
                    'qty_available' => $rstStock['current_stock'],
                    'qty_shortage' => $detail->qty_planned - $rstStock['current_stock'],
                    'reason' => 'Stok RST tidak cukup di Gudang Pembahanan',
                ];
            }
        }

        return [
            'needs_sawmill' => $needsSawmill,
            'skip_sawmill' => !$needsSawmill,
            'next_stage' => $needsSawmill
                ? ProductionOrder::STAGE_SAWMILL
                : ProductionOrder::STAGE_PEMBAHANAN,
            'missing_items' => $missingItems,
            'summary' => $needsSawmill
                ? 'PO harus lewat Sawmill terlebih dahulu'
                : 'PO bisa langsung ke Pembahanan (RST tersedia)',
        ];
    }

    /**
     * Cek stok RST di Gudang Pembahanan (Warehouse ID = 4)
     *
     * @param int $itemId
     * @param float $qtyNeeded
     * @return array
     */
    private function checkRstStock(int $itemId, float $qtyNeeded): array
    {
        $currentStock = Inventory::where('item_id', $itemId)
            ->where('warehouse_id', self::PEMBAHANAN_WAREHOUSE_ID)
            ->sum('qty');

        return [
            'available' => $currentStock >= $qtyNeeded,
            'current_stock' => $currentStock,
            'needed' => $qtyNeeded,
            'shortage' => max(0, $qtyNeeded - $currentStock),
        ];
    }

    /**
     * Cek apakah PO bisa skip sawmill (untuk validasi manual)
     *
     * @param ProductionOrder $po
     * @return bool
     */
    public function canSkipSawmill(ProductionOrder $po): bool
    {
        $routing = $this->determineRouting($po);
        return $routing['skip_sawmill'];
    }

    /**
     * Get detail stok RST untuk semua item dalam PO
     *
     * @param ProductionOrder $po
     * @return array
     */
    public function getRstStockDetails(ProductionOrder $po): array
    {
        $details = [];

        foreach ($po->details as $detail) {
            $item = $detail->item;
            $stockInfo = $this->checkRstStock($item->id, $detail->qty_planned);

            $details[] = [
                'item_id' => $item->id,
                'item_name' => $item->name,
                'item_code' => $item->code,
                'qty_needed' => $detail->qty_planned,
                'qty_available' => $stockInfo['current_stock'],
                'is_sufficient' => $stockInfo['available'],
                'shortage' => $stockInfo['shortage'],
                'production_route' => $item->production_route,
                'needs_sawmill' => $item->needsSawmill(),
            ];
        }

        return $details;
    }
}
