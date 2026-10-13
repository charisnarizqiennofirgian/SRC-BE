<?php

namespace App\Console\Commands;

use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Services\InitialStockClaimService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditStokAwal extends Command
{
    protected $signature = 'app:audit-stok-awal {--nolkan= : ID production_order_detail dipisah koma yang stok awalnya dinolkan} {--nolkan-semua : Nolkan semua baris yang terdaftar}';

    protected $description = 'Daftar stok awal (initial_stock_snapshot) di PO aktif beserta stok fisik & total klaim per item';

    public function handle(InitialStockClaimService $claimService): int
    {
        $rows = ProductionOrderDetail::where('initial_stock_snapshot', '>', 0)
            ->whereHas('productionOrder', fn ($q) => $q->whereNotIn('status', [
                ProductionOrder::STATUS_COMPLETED,
                ProductionOrder::STATUS_CANCELLED,
            ]))
            ->with(['productionOrder.salesOrder', 'item'])
            ->orderBy('item_id')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada stok awal terisi di PO aktif.');
            return self::SUCCESS;
        }

        $table = [];
        foreach ($rows->groupBy('item_id') as $itemId => $group) {
            $physical = array_sum(array_column($claimService->physicalByWarehouse((int) $itemId), 'qty'));
            $claimed = (float) $group->sum('initial_stock_snapshot');
            foreach ($group as $d) {
                $table[] = [
                    $d->id,
                    $d->productionOrder?->salesOrder?->so_number ?? $d->productionOrder?->po_number ?? '-',
                    mb_strimwidth($d->item?->name ?? '-', 0, 45, '…'),
                    (float) $d->qty_planned,
                    (float) $d->initial_stock_snapshot,
                    $physical,
                    $claimed,
                    $claimed > $physical ? 'LEBIH' : '',
                ];
            }
        }

        $this->table(['pod_id', 'SO', 'Item', 'Target', 'Stok Awal', 'Fisik Item', 'Total Klaim', 'Cek'], $table);

        $ids = [];
        if ($this->option('nolkan-semua')) {
            $ids = $rows->pluck('id')->all();
        } elseif ($this->option('nolkan')) {
            $ids = array_values(array_intersect(
                array_map('intval', array_filter(explode(',', $this->option('nolkan')))),
                $rows->pluck('id')->all()
            ));
        }

        if (empty($ids)) {
            return self::SUCCESS;
        }

        $updated = DB::transaction(fn () => ProductionOrderDetail::whereIn('id', $ids)->update(['initial_stock_snapshot' => 0]));
        $this->info("Stok awal dinolkan: {$updated} baris (" . implode(', ', $ids) . ').');

        return self::SUCCESS;
    }
}
