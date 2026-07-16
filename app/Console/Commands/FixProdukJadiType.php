<?php

namespace App\Console\Commands;

use App\Models\Item;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixProdukJadiType extends Command
{
    protected $signature = 'app:fix-produk-jadi-type {--dry-run : Tampilkan apa yang akan diubah tanpa menyimpan}';
    protected $description = 'Backfill items.type NULL -> finished_good untuk item berkategori Produk Jadi, supaya bisa dikenali sistem (mis. fitur Set Jadi berbasis BOM). Aman dijalankan berulang kali (idempotent) — cari kategori by nama, bukan ID.';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $category = DB::table('categories')->whereRaw('LOWER(name) = ?', ['produk jadi'])->first();
        if (!$category) {
            $this->error("Kategori 'Produk Jadi' tidak ditemukan di database ini. Dibatalkan.");
            return 1;
        }
        $this->info("Kategori Produk Jadi: id={$category->id}");

        $items = Item::where('category_id', $category->id)->whereNull('type')->get(['id', 'code', 'name']);
        $this->line("Item dengan type NULL: {$items->count()}");

        foreach ($items as $item) {
            $this->line("  {$item->code} — {$item->name}");
        }

        if ($items->isNotEmpty() && !$dryRun) {
            $updated = Item::where('category_id', $category->id)->whereNull('type')
                ->update(['type' => Item::TYPE_FINISHED_GOOD]);
            $this->info("OK: {$updated} item di-set type=finished_good.");
        } elseif ($items->isNotEmpty()) {
            $this->line('(dry-run, tidak disimpan)');
        }

        if ($dryRun) {
            $this->warn('Jalankan tanpa --dry-run untuk menyimpan perubahan.');
        }

        return 0;
    }
}
