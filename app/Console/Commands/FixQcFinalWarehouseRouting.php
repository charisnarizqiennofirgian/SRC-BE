<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixQcFinalWarehouseRouting extends Command
{
    protected $signature = 'app:fix-qc-final-warehouse-routing {--dry-run : Tampilkan apa yang akan diubah tanpa menyimpan}';

    protected $description = 'Perbaiki stok item yang salah nyasar ke Gudang Packing gara-gara bug lama QcFinalController::store() (item lolos QC ditaruh langsung ke Packing, bukan ke Gudang QC Final transit). Cuma memindahkan item yang SELURUH riwayat masuknya ke Packing berasal dari transaksi QC_FINAL (aman/tidak ambigu) — item campuran (ada juga deposit Packing yang sah dari proses Packing biasa) dilewati dan dilaporkan untuk dicek manual. Idempotent — aman dijalankan berulang kali.';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $packingWh = Warehouse::where('code', 'PACKING')->first();
        $qcFinalWh = Warehouse::where('code', 'QC_FINAL')->first();

        if (!$packingWh || !$qcFinalWh) {
            $this->error('Gudang PACKING atau QC_FINAL tidak ditemukan di database ini. Dibatalkan.');
            return 1;
        }

        $grouped = InventoryLog::where('warehouse_id', $packingWh->id)
            ->where('direction', 'IN')
            ->select('item_id', 'transaction_type', DB::raw('SUM(qty) as total'))
            ->groupBy('item_id', 'transaction_type')
            ->havingRaw('SUM(qty) > 0')
            ->get()
            ->groupBy('item_id');

        $pureQcFinalItemIds = [];
        $mixedItemIds = [];

        foreach ($grouped as $itemId => $rows) {
            $types = $rows->pluck('transaction_type')->unique();
            if ($types->count() === 1 && $types->first() === 'QC_FINAL') {
                $pureQcFinalItemIds[] = $itemId;
            } elseif ($types->contains('QC_FINAL')) {
                $mixedItemIds[] = $itemId;
            }
        }

        $this->info('Item murni dari QC_FINAL (aman direlokasi ke Gudang QC Final): ' . count($pureQcFinalItemIds));
        $this->info('Item campuran QC_FINAL + sumber Packing lain (DILEWATI, cek manual): ' . count($mixedItemIds));

        if (!empty($mixedItemIds)) {
            $this->newLine();
            $this->warn('Item campuran yang dilewati:');
            foreach ($mixedItemIds as $id) {
                $item = DB::table('items')->where('id', $id)->first(['code', 'name']);
                $this->line("  - {$item->code} / {$item->name} (item_id={$id})");
            }
        }

        $this->newLine();
        $moved = 0;

        foreach ($pureQcFinalItemIds as $itemId) {
            $item = DB::table('items')->where('id', $itemId)->first(['code', 'name']);
            $balance = (float) Inventory::where('item_id', $itemId)
                ->where('warehouse_id', $packingWh->id)
                ->value('qty_pcs');

            if ($balance <= 0) {
                continue;
            }

            $this->line("  {$item->code} / {$item->name}: pindah {$balance} pcs, Gudang Packing -> Gudang QC Final");

            if ($dryRun) {
                $moved++;
                continue;
            }

            DB::transaction(function () use ($itemId, $balance, $packingWh, $qcFinalWh) {
                Inventory::where('item_id', $itemId)->where('warehouse_id', $packingWh->id)
                    ->lockForUpdate()->decrement('qty_pcs', $balance);

                $qcInv = Inventory::where('item_id', $itemId)->where('warehouse_id', $qcFinalWh->id)
                    ->lockForUpdate()->first();
                if ($qcInv) {
                    $qcInv->increment('qty_pcs', $balance);
                } else {
                    Inventory::create(['item_id' => $itemId, 'warehouse_id' => $qcFinalWh->id, 'qty_pcs' => $balance]);
                }

                $now = now();
                InventoryLog::create([
                    'date' => $now->toDateString(), 'time' => $now->toTimeString(),
                    'item_id' => $itemId, 'warehouse_id' => $packingWh->id,
                    'qty' => $balance, 'qty_m3' => 0, 'direction' => 'OUT',
                    'transaction_type' => 'QC_FINAL_KOREKSI',
                    'notes' => 'Koreksi data: stok salah taruh di Gudang Packing akibat bug QcFinalController lama, dipindah ke Gudang QC Final (app:fix-qc-final-warehouse-routing)',
                ]);
                InventoryLog::create([
                    'date' => $now->toDateString(), 'time' => $now->toTimeString(),
                    'item_id' => $itemId, 'warehouse_id' => $qcFinalWh->id,
                    'qty' => $balance, 'qty_m3' => 0, 'direction' => 'IN',
                    'transaction_type' => 'QC_FINAL_KOREKSI',
                    'notes' => 'Koreksi data: stok salah taruh di Gudang Packing akibat bug QcFinalController lama, dipindah ke Gudang QC Final (app:fix-qc-final-warehouse-routing)',
                ]);
            });

            $moved++;
        }

        $this->newLine();
        $this->info("Selesai. Item dipindah: {$moved}.");

        if ($dryRun) {
            $this->warn('Ini masih dry-run. Jalankan tanpa --dry-run untuk menyimpan perubahan.');
        }

        return 0;
    }
}
