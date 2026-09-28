<?php

namespace App\Console\Commands;

use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\SalesOrder;
use App\Services\ProductionOrderDetailSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncStaleProductionOrderDetails extends Command
{
    protected $signature = 'app:sync-stale-po-details
        {--so= : Batasi ke satu nomor SO (contoh: SO-2026-0017)}
        {--reassign=* : Pindahkan baris PO dari item lama ke item baru sebelum sync, format SO:ITEM_LAMA:ITEM_BARU (contoh: SO-2026-0010:27447:27883)}
        {--dry-run : Tampilkan apa yang akan diubah tanpa menyimpan}';

    protected $description = 'Sinkronkan production_order_details yang sales_order_detail_id-nya basi (SO diedit setelah PO dibuat): repoint FK ke detail SO aktif, tambah item SO yang belum ada di PO, hapus item PO yang sudah tidak ada di SO (kecuali sudah ada progres produksi). Idempotent.';

    public function handle(ProductionOrderDetailSyncService $syncService)
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        DB::beginTransaction();

        foreach ((array) $this->option('reassign') as $spec) {
            $parts = explode(':', $spec);
            if (count($parts) !== 3) {
                $this->error("Format --reassign salah: {$spec} (harus SO:ITEM_LAMA:ITEM_BARU)");
                DB::rollBack();
                return 1;
            }
            [$soNumber, $oldItemId, $newItemId] = $parts;

            $salesOrder = SalesOrder::where('so_number', $soNumber)->first();
            if (!$salesOrder) {
                $this->error("SO {$soNumber} tidak ditemukan.");
                DB::rollBack();
                return 1;
            }

            $activeDetails = $salesOrder->details()->where('item_id', $newItemId)->orderBy('id')->get();
            if ($activeDetails->isEmpty()) {
                $this->error("Item {$newItemId} tidak ada di detail aktif {$soNumber}.");
                DB::rollBack();
                return 1;
            }

            $rows = ProductionOrderDetail::whereIn('production_order_id', $salesOrder->productionOrders()->pluck('id'))
                ->where('item_id', $oldItemId)
                ->orderBy('id')
                ->get();

            foreach ($rows->values() as $i => $row) {
                $target = $activeDetails[$i] ?? $activeDetails->last();
                $row->update(['item_id' => $newItemId, 'sales_order_detail_id' => $target->id]);
                $this->line("Reassign {$soNumber}: POD {$row->id} item {$oldItemId} -> {$newItemId} (sod {$target->id})");
            }

            if ($rows->isEmpty()) {
                $this->warn("Reassign {$soNumber}: tidak ada baris PO dengan item {$oldItemId}.");
            }
        }

        $query = ProductionOrder::whereHas('salesOrder')
            ->whereHas('details', function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNull('sales_order_detail_id')
                        ->orWhereDoesntHave('salesOrderDetail');
                });
            })
            ->with('salesOrder');

        if ($soNumber = $this->option('so')) {
            $query->whereHas('salesOrder', fn ($q) => $q->where('so_number', $soNumber));
        }

        $productionOrders = $query->orderBy('id')->get();

        $this->info("PO dengan detail basi: {$productionOrders->count()}");

        if ($productionOrders->isEmpty()) {
            $this->info('Tidak ada yang perlu diperbaiki.');
        }

        $totalBlocked = 0;

        foreach ($productionOrders as $productionOrder) {
            DB::beginTransaction();
            try {
                $sync = $syncService->sync($productionOrder, $productionOrder->salesOrder);

                $this->newLine();
                $this->info("{$productionOrder->salesOrder->so_number} | PO id={$productionOrder->id} \"{$productionOrder->po_number}\" (status {$productionOrder->status})");
                $this->line("  FK di-repoint: {$sync['repointed']}");

                foreach ($sync['added'] as $detail) {
                    $this->line("  + ditambah: [{$detail->item_id}] " . ($detail->item?->name ?? '-') . " qty {$detail->qty_planned}");
                }
                foreach ($sync['removed'] as $detail) {
                    $this->line("  - dihapus : POD {$detail->id} [{$detail->item_id}] " . ($detail->item?->name ?? '-') . " qty {$detail->qty_planned}");
                }
                foreach ($sync['blocked'] as $detail) {
                    $this->error("  ! TIDAK dihapus (ada progres produksi, cek manual): POD {$detail->id} [{$detail->item_id}] " . ($detail->item?->name ?? '-'));
                    $totalBlocked++;
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("  Gagal untuk PO id={$productionOrder->id}: {$e->getMessage()}");
            }
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->newLine();
        if ($totalBlocked > 0) {
            $this->warn("{$totalBlocked} baris PO tidak dihapus karena sudah ada progres produksi — cek manual.");
        }
        $this->info($dryRun ? 'Dry-run selesai. Jalankan tanpa --dry-run untuk menyimpan.' : 'Selesai.');

        return 0;
    }
}
