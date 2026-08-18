<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixFinishedGoodStockDesync extends Command
{
    protected $signature = 'app:fix-finished-good-stock-desync {--dry-run : Tampilkan apa yang akan diubah tanpa menyimpan}';

    protected $description = 'Perbaiki items.stock item Produk Jadi yang lebih kecil dari total inventories (bug lama AssemblingProductionController/PackingController: output produk hasil rakit komponen cuma nambah inventories, lupa nambah items.stock). Cuma menyentuh item yang items.stock < SUM(inventories.qty_pcs) — item dengan arah selisih sebaliknya (items.stock lebih besar) dilewati dan dilaporkan untuk dicek manual, bukan bug yang sama. Idempotent — aman dijalankan berulang kali.';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $underCounted = DB::table('items')
            ->join(DB::raw('(SELECT item_id, SUM(qty_pcs) as total_inv FROM inventories GROUP BY item_id) inv'), 'items.id', '=', 'inv.item_id')
            ->where('items.type', 'finished_good')
            ->whereRaw('items.stock < inv.total_inv - 0.01')
            ->select('items.id', 'items.code', 'items.name', 'items.stock', 'inv.total_inv')
            ->get();

        $overCounted = DB::table('items')
            ->join(DB::raw('(SELECT item_id, SUM(qty_pcs) as total_inv FROM inventories GROUP BY item_id) inv'), 'items.id', '=', 'inv.item_id')
            ->where('items.type', 'finished_good')
            ->whereRaw('items.stock > inv.total_inv + 0.01')
            ->select('items.id', 'items.code', 'items.name', 'items.stock', 'inv.total_inv')
            ->get();

        $this->info('Item kurang-hitung (bakal dikoreksi naik): ' . $underCounted->count());
        $this->info('Item lebih-hitung (DILEWATI, cek manual, bukan bug yang sama): ' . $overCounted->count());

        if ($overCounted->isNotEmpty()) {
            $this->newLine();
            $this->warn('Item lebih-hitung yang dilewati:');
            foreach ($overCounted as $i) {
                $this->line("  - {$i->code} / {$i->name} (id={$i->id}): stock={$i->stock}, inventories={$i->total_inv}");
            }
        }

        $this->newLine();
        $fixed = 0;

        foreach ($underCounted as $i) {
            $this->line("  {$i->code} / {$i->name}: stock {$i->stock} -> {$i->total_inv}");

            if ($dryRun) {
                $fixed++;
                continue;
            }

            DB::table('items')->where('id', $i->id)->update(['stock' => $i->total_inv]);
            $fixed++;
        }

        $this->newLine();
        $this->info("Selesai. Item dikoreksi: {$fixed}.");

        if ($dryRun) {
            $this->warn('Ini masih dry-run. Jalankan tanpa --dry-run untuk menyimpan perubahan.');
        }

        return 0;
    }
}
