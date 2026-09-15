<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixDashboardMonitoringDobelCount extends Command
{
    protected $signature = 'app:fix-dashboard-monitoring-dobel-count {--dry-run : Tampilkan apa yang akan diubah tanpa menyimpan}';

    protected $description = 'Koreksi qty_produk_jadi dobel-deklarasi (Moulding/Mesin) & ledger Anyam SO-2026-06-0005, seri koreksi Dashboard Monitoring 14-15 September 2026. Tiap kasus dicari via document_number dan diverifikasi ulang kondisinya (bukan asal timpa) — kasus yang kondisinya sudah tidak cocok dengan kondisi dev saat koreksi ini dibuat akan dilewati otomatis dan perlu dicek manual.';

    private array $nullCases = [
        ['doc' => 'MLD-202609-035', 'table' => 'moulding_productions', 'label' => 'Voodoo Table 90x90cm (SO-2026-0008)'],
        ['doc' => 'MLD-202607-070', 'table' => 'moulding_productions', 'label' => 'PATIO 3 SEATER SOFA LAGUNA/LIGHT GREY (SO-2026-0006)'],
        ['doc' => 'MLD-202608-013', 'table' => 'moulding_productions', 'label' => 'SAND SUNBED FULL NATURAL TEAK REV.20 (SO-2026-0006)'],
        ['doc' => 'MSN-202608-002', 'table' => 'mesin_productions',    'label' => 'KILT LOUNGE ARMCHAIR XL BEIGE (SO-2026-05-0003)'],
        ['doc' => 'MSN-202608-003', 'table' => 'mesin_productions',    'label' => 'KILT LOUNGE ARMCHAIR XL BEIGE (SO-2026-05-0003)'],
    ];

    private array $zeroCases = [
        ['doc' => 'MLD-202607-034', 'table' => 'moulding_productions', 'label' => 'SANDY BACKREST NATURAL TEAK (SO-2026-0006)'],
        ['doc' => 'MLD-202607-033', 'table' => 'moulding_productions', 'label' => 'GRAND LIFE COFFEE TABLE FRAME (SO-2026-0006)'],
        ['doc' => 'MSN-202607-009', 'table' => 'mesin_productions',    'label' => 'LAREN TOP TABLE 89X89 IN TEAK PICKLED (SO-2026-06-0005)'],
        ['doc' => 'MSN-202607-010', 'table' => 'mesin_productions',    'label' => 'LAREN TOP TABLE 89X89 IN TEAK PICKLED (SO-2026-06-0005)'],
        ['doc' => 'MSN-202607-011', 'table' => 'mesin_productions',    'label' => 'LAREN TOP TABLE 89X89 IN TEAK PICKLED (SO-2026-06-0005)'],
        ['doc' => 'MLD-202607-072', 'table' => 'moulding_productions', 'label' => 'RAFAEL FRAME DINING TABLE 264X154 BRUSHED (SO-2026-05-0003)'],
        ['doc' => 'MLD-202607-074', 'table' => 'moulding_productions', 'label' => 'RAFAEL FRAME DINING TABLE 264X154 BRUSHED (SO-2026-05-0003)'],
        ['doc' => 'MLD-202607-071', 'table' => 'moulding_productions', 'label' => 'RAFAEL FRAME DINING TABLE 264X155 PICKLED (SO-2026-05-0003)'],
        ['doc' => 'MLD-202607-075', 'table' => 'moulding_productions', 'label' => 'RAFAEL FRAME DINING TABLE 264X155 PICKLED (SO-2026-05-0003)'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $this->info('--- Koreksi qty_produk_jadi (dobel deklarasi batch fisik yang sama) ---');
        foreach ($this->nullCases as $case) {
            $this->fixQtyProdukJadiCase($case, $dryRun, false);
        }
        foreach ($this->zeroCases as $case) {
            $this->fixQtyProdukJadiCase($case, $dryRun, true);
        }

        $this->newLine();
        $this->info('--- Koreksi ledger Anyam SO-2026-06-0005 (PATIO DINING ARMCHAIR) ---');
        $this->fixAnyamCase($dryRun);

        $this->newLine();
        $this->info('--- Koreksi Moulding legacy salah estimasi BOM (2x lipat) — SO-2026-06-0005 baris 1 ---');
        $this->fixMouldingLegacyOverEstimateCase($dryRun);

        if ($dryRun) {
            $this->newLine();
            $this->warn('Jalankan tanpa --dry-run untuk menyimpan.');
        }

        return 0;
    }

    private function fixQtyProdukJadiCase(array $case, bool $dryRun, bool $toZero): void
    {
        $doc   = $case['doc'];
        $table = $case['table'];

        $row = DB::table($table)->where('document_number', $doc)->first();
        if (!$row) {
            $this->error("[$doc] tidak ditemukan di $table — dilewati.");
            return;
        }

        $current  = $row->qty_produk_jadi;
        $newValue = $toZero ? 0.0 : null;

        $alreadyApplied = $toZero
            ? ($current !== null && (float) $current === 0.0)
            : ($current === null);

        if ($alreadyApplied) {
            $this->line("[$doc] sudah dalam kondisi yang benar, dilewati.");
            return;
        }

        $siblingSum = DB::table($table)
            ->where('production_order_detail_id', $row->production_order_detail_id)
            ->where('id', '!=', $row->id)
            ->whereNotNull('qty_produk_jadi')
            ->sum('qty_produk_jadi');

        if ($siblingSum <= 0) {
            $this->warn("[$doc] ({$case['label']}) TIDAK ditemukan deklarasi kembar (sibling qty_produk_jadi) di detail yang sama — dilewati, kemungkinan koreksi ini sudah tidak relevan di database ini. Cek manual sebelum memutuskan.");
            return;
        }

        $this->info("[$doc] ({$case['label']}) qty_produk_jadi: " . var_export($current, true) . ' -> ' . ($newValue === null ? 'NULL' : $newValue) . " (sibling non-null lain di detail sama: $siblingSum)");

        if (!$dryRun) {
            DB::table($table)->where('id', $row->id)->update(['qty_produk_jadi' => $newValue]);
        }
    }

    private function fixAnyamCase(bool $dryRun): void
    {
        $itemId              = 26298;
        $sandingWarehouseId  = 9;
        $anyamWarehouseId    = 19;

        $out = DB::table('inventory_logs')->where('reference_number', 'ANYAM-202609-004')->where('direction', 'OUT')->first();
        $in  = DB::table('inventory_logs')->where('reference_number', 'ANYAM-202609-004')->where('direction', 'IN')->first();

        if (!$out || !$in) {
            $this->error('[Anyam SO-2026-06-0005] log ANYAM-202609-004 tidak ditemukan — dilewati.');
            return;
        }

        $alreadyAdjusted = DB::table('inventory_logs')->where('reference_number', 'KOREKSI-ANYAM-202609-004')->exists();
        if ($alreadyAdjusted) {
            $this->line('[Anyam SO-2026-06-0005] koreksi sudah pernah dijalankan sebelumnya, dilewati.');
            return;
        }

        if ((float) $out->qty !== 60.0 || (float) $in->qty !== 60.0) {
            $this->warn('[Anyam SO-2026-06-0005] qty log OUT/IN sudah bukan 60 (state sudah berubah sejak koreksi ini dibuat) — dilewati. Hitung ulang manual dari net inventory_logs per gudang, jangan asumsikan angka 60->62 masih berlaku.');
            return;
        }

        $this->info('[Anyam SO-2026-06-0005] OUT & IN: 60 -> 62, + ADJUSTMENT +2 pcs ke Gudang Sanding, items.stock disesuaikan ulang dari SUM(inventories)');

        if ($dryRun) {
            return;
        }

        DB::transaction(function () use ($out, $in, $itemId, $sandingWarehouseId, $anyamWarehouseId) {
            DB::table('inventory_logs')->where('id', $out->id)->update(['qty' => 62]);
            DB::table('inventory_logs')->where('id', $in->id)->update(['qty' => 62]);

            DB::table('inventory_logs')->insert([
                'date'             => $out->date,
                'time'             => now()->format('H:i:s'),
                'item_id'          => $itemId,
                'warehouse_id'     => $sandingWarehouseId,
                'qty'              => 2,
                'qty_m3'           => 0,
                'direction'        => 'IN',
                'transaction_type' => 'ADJUSTMENT',
                'reference_type'   => 'Adjustment',
                'reference_id'     => $itemId,
                'reference_number' => 'KOREKSI-ANYAM-202609-004',
                'division'         => null,
                'notes'            => 'Koreksi qty Anyam PATIO DINING ARMCHAIR SO-2026-06-0005 (60->62), penyesuaian sisa Gudang Sanding',
                'grade'            => null,
                'user_id'          => $out->user_id,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            foreach ([$sandingWarehouseId, $anyamWarehouseId] as $warehouseId) {
                $net = DB::table('inventory_logs')
                    ->where('item_id', $itemId)
                    ->where('warehouse_id', $warehouseId)
                    ->selectRaw("SUM(CASE WHEN direction = 'IN' THEN qty ELSE -qty END) as net")
                    ->value('net');

                DB::table('inventories')
                    ->where('item_id', $itemId)
                    ->where('warehouse_id', $warehouseId)
                    ->update(['qty_pcs' => $net]);
            }

            $totalStock = DB::table('inventories')->where('item_id', $itemId)->sum('qty_pcs');
            DB::table('items')->where('id', $itemId)->update(['stock' => $totalStock]);
        });
    }

    private function fixMouldingLegacyOverEstimateCase(bool $dryRun): void
    {
        $doc           = 'MLD-202607-003';
        $expectedValue = 31.0;
        $label         = 'PATIO DINING ARMCHAIR NATURAL TEAK AND ROPE LIGHT GREY/LIGHT GREY (SO-2026-06-0005, baris 1)';

        $row = DB::table('moulding_productions')->where('document_number', $doc)->first();
        if (!$row) {
            $this->error("[$doc] tidak ditemukan — dilewati.");
            return;
        }

        if ($row->qty_produk_jadi !== null && (float) $row->qty_produk_jadi === $expectedValue) {
            $this->line("[$doc] sudah bernilai $expectedValue, dilewati.");
            return;
        }

        if ($row->qty_produk_jadi !== null) {
            $this->warn("[$doc] qty_produk_jadi sudah diisi manual ({$row->qty_produk_jadi}, bukan NULL) — dilewati, kemungkinan sudah dikoreksi staf atau kondisi berbeda. Cek manual.");
            return;
        }

        $mesinQty = (float) DB::table('mesin_productions')
            ->where('production_order_detail_id', $row->production_order_detail_id)
            ->sum('qty_produk_jadi');

        if (abs($mesinQty - $expectedValue) > 0.001) {
            $this->warn("[$doc] ({$label}) qty Mesin di detail yang sama sekarang {$mesinQty}, bukan {$expectedValue} seperti saat koreksi ini dibuat — dilewati, state server sudah beda. Cek manual, jangan asal timpa.");
            return;
        }

        $this->info("[$doc] ({$label}) qty_produk_jadi: NULL (estimasi BOM legacy salah, ~62 karena output komponen 2x lipat) -> {$expectedValue} (selaras dengan Mesin & Anyam)");

        if (!$dryRun) {
            DB::table('moulding_productions')->where('id', $row->id)->update(['qty_produk_jadi' => $expectedValue]);
        }
    }
}
