<?php

namespace App\Console\Commands;

use App\Models\Item;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixKomponenNaturalWarna extends Command
{
    protected $signature = 'app:fix-komponen-natural-warna {--dry-run : Tampilkan apa yang akan diubah tanpa menyimpan}';
    protected $description = 'Perbaiki item kategori Komponen yang type-nya NULL (harusnya component) sehingga breakdown Qty Natural/Warna tidak ter-update saat Moulding/Mesin/Assembling, lalu rekonsiliasi selisih qty_natural+qty_warna vs stok fisik gudang. Aman dijalankan berulang kali (idempotent) — cari kategori by nama, bukan ID, jadi aman di database manapun.';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $category = DB::table('categories')->whereRaw('LOWER(name) = ?', ['komponen'])->first();
        if (!$category) {
            $this->error("Kategori 'Komponen' tidak ditemukan di database ini. Dibatalkan.");
            return 1;
        }
        $this->info("Kategori Komponen: id={$category->id}");

        // === 1. Backfill type NULL -> component ===
        $this->newLine();
        $this->info('=== 1. Backfill items.type NULL -> component ===');

        $nullTypeCount = Item::where('category_id', $category->id)->whereNull('type')->count();
        $this->line("  Item dengan type NULL: {$nullTypeCount}");

        if ($nullTypeCount > 0 && !$dryRun) {
            $updated = Item::where('category_id', $category->id)->whereNull('type')
                ->update(['type' => Item::TYPE_COMPONENT]);
            $this->info("  OK: {$updated} item di-set type=component.");
        } elseif ($nullTypeCount > 0) {
            $this->line('  (dry-run, tidak disimpan)');
        }

        // === 2. Rekonsiliasi qty_natural/qty_warna vs stok fisik ===
        $this->newLine();
        $this->info('=== 2. Rekonsiliasi Qty Natural/Warna vs stok fisik gudang ===');

        $mismatches = DB::table('items as i')
            ->leftJoin('inventories as inv', 'inv.item_id', '=', 'i.id')
            ->where('i.category_id', $category->id)
            ->select('i.id', 'i.code', 'i.name', 'i.qty_natural', 'i.qty_warna')
            ->groupBy('i.id', 'i.code', 'i.name', 'i.qty_natural', 'i.qty_warna')
            ->havingRaw('COALESCE(SUM(inv.qty_pcs),0) - (i.qty_natural + i.qty_warna) != 0')
            ->get();

        $this->line("  Item dengan selisih: {$mismatches->count()}");

        $fixed = 0;
        $totalGap = 0;

        foreach ($mismatches as $row) {
            $phys = (float) DB::table('inventories')->where('item_id', $row->id)->sum('qty_pcs');
            $nw = (float) $row->qty_natural + (float) $row->qty_warna;
            $gap = $phys - $nw;

            if (round($gap, 4) === 0.0) {
                continue;
            }

            $newNatural = (float) $row->qty_natural + $gap;
            $this->line(sprintf(
                "  %s (%s): fisik=%.2f natural+warna_lama=%.2f selisih=%.2f -> qty_natural baru=%.2f",
                $row->code,
                $row->name,
                $phys,
                $nw,
                $gap,
                $newNatural
            ));

            if (!$dryRun) {
                DB::table('items')->where('id', $row->id)->update([
                    'qty_natural' => $newNatural,
                    'stock'       => $newNatural + (float) $row->qty_warna,
                ]);
            }

            $fixed++;
            $totalGap += $gap;
        }

        $this->newLine();
        $this->info("Selesai. Item direkonsiliasi: {$fixed}, total qty ditambahkan ke Natural: {$totalGap}.");

        if ($dryRun) {
            $this->warn('Ini masih dry-run. Jalankan tanpa --dry-run untuk menyimpan perubahan.');
        }

        return 0;
    }
}
