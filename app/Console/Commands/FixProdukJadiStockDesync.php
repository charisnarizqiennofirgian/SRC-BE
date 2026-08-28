<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use Illuminate\Console\Command;

class FixProdukJadiStockDesync extends Command
{
    protected $signature = 'app:fix-produk-jadi-stock-desync {--dry-run : Tampilkan apa yang akan diubah tanpa menyimpan}';

    protected $description = 'Samakan items.stock (cache global) ke SUM(inventories.qty_pcs) untuk item kategori Produk Jadi yang desync. Baris yang SUM(inventories) tidak cocok dengan inventory_logs NET (IN-OUT) dilewati & dilaporkan (butuh cek manual). Idempotent.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $catIds = Category::whereRaw('LOWER(name) LIKE ?', ['%produk jadi%'])->pluck('id');
        if ($catIds->isEmpty()) {
            $this->error("Kategori 'Produk Jadi' tidak ditemukan. Dibatalkan.");
            return 1;
        }

        $items = Item::whereIn('category_id', $catIds)->get();
        $this->line("Item Produk Jadi diperiksa: {$items->count()}");

        $fixed = [];
        $skipped = [];

        foreach ($items as $item) {
            $invSum = (float) Inventory::where('item_id', $item->id)->sum('qty_pcs');
            $cache  = (float) $item->stock;

            if (abs($invSum - $cache) < 0.001) {
                continue;
            }

            $logIn  = (float) InventoryLog::where('item_id', $item->id)->where('direction', 'IN')->sum('qty');
            $logOut = (float) InventoryLog::where('item_id', $item->id)->where('direction', 'OUT')->sum('qty');
            $logNet = $logIn - $logOut;

            if (abs($logNet - $invSum) > 0.001) {
                $skipped[] = [$item->code, $item->name, $cache, $invSum, $logNet];
                continue;
            }

            $fixed[] = [$item->code, $item->name, $cache, $invSum];

            if (!$dryRun) {
                $item->stock = $invSum;
                $item->save();
            }
        }

        $this->newLine();
        if ($fixed) {
            $this->info(($dryRun ? 'AKAN diperbaiki' : 'Diperbaiki') . ': ' . count($fixed));
            $this->table(['Kode', 'Nama', 'stock lama', 'stock baru'], $fixed);
        } else {
            $this->info('Tidak ada yang perlu diperbaiki.');
        }

        if ($skipped) {
            $this->newLine();
            $this->warn('DILEWATI (SUM inventories != inventory_logs NET — cek manual): ' . count($skipped));
            $this->table(['Kode', 'Nama', 'items.stock', 'SUM(inv)', 'log NET'], $skipped);
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Jalankan tanpa --dry-run untuk menyimpan.');
        }

        return 0;
    }
}
