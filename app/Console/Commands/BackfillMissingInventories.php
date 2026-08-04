<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillMissingInventories extends Command
{
    protected $signature = 'app:backfill-missing-inventories {--dry-run : Tampilkan apa yang akan dibuat tanpa menyimpan}';
    protected $description = 'Buat baris inventories yang hilang untuk item kategori Bahan Operasional/Karton Box yang punya items.stock > 0 tapi belum pernah punya baris inventories sama sekali (item lama yang stoknya cuma pernah tercatat di cache global, tidak pernah di tabel stok per-gudang). qty_pcs diisi dari items.stock. Idempotent: hanya menyentuh item yang benar-benar nol baris inventories, aman dijalankan berulang.';

    // Kategori -> kode gudang tujuan, mengikuti gudang yang sudah dipakai mayoritas item sejenis di kategori itu
    private const CATEGORY_WAREHOUSE = [
        'Bahan Operasional' => 'UMUM',    // Gudang Bahan Operasional
        'Karton Box'        => 'PACKING', // Gudang Packing (Barang Jadi)
    ];

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $warehouseIds = [];
        foreach (self::CATEGORY_WAREHOUSE as $catName => $whCode) {
            $wh = Warehouse::where('code', $whCode)->first();
            if (!$wh) {
                $this->error("Gudang dengan kode {$whCode} tidak ditemukan — dibatalkan.");
                return 1;
            }
            $warehouseIds[$catName] = $wh->id;
        }

        $items = Item::whereHas('category', function ($q) {
                $q->whereIn('name', array_keys(self::CATEGORY_WAREHOUSE));
            })
            ->with('category:id,name')
            ->where('stock', '>', 0)
            ->whereDoesntHave('inventories')
            ->get(['id', 'code', 'name', 'stock', 'category_id']);

        $this->info("Item ditemukan (stock > 0, nol baris inventories): {$items->count()}");
        $this->newLine();

        $created = 0;

        foreach ($items as $item) {
            $catName = $item->category?->name;
            $warehouseId = $warehouseIds[$catName] ?? null;

            if (!$warehouseId) {
                $this->warn("SKIP {$item->code} ({$item->name}): kategori '{$catName}' tidak dikenali.");
                continue;
            }

            $whCode = self::CATEGORY_WAREHOUSE[$catName];
            $this->line("{$item->code} ({$item->name}): stock={$item->stock} -> gudang {$whCode}");

            if (!$dryRun) {
                DB::transaction(function () use ($item, $warehouseId) {
                    // Guard idempotent tambahan di dalam transaksi (jaga-jaga race condition)
                    $exists = Inventory::where('item_id', $item->id)->exists();
                    if ($exists) {
                        return;
                    }

                    Inventory::create([
                        'item_id'      => $item->id,
                        'warehouse_id' => $warehouseId,
                        'qty_pcs'      => $item->stock,
                        'qty_natural'  => 0,
                        'qty_warna'    => 0,
                    ]);
                });
            }

            $created++;
        }

        $this->newLine();
        $this->info("Selesai. Baris inventories yang " . ($dryRun ? 'akan' : 'sudah') . " dibuat: {$created}.");

        if ($dryRun) {
            $this->warn('Ini masih dry-run. Jalankan tanpa --dry-run untuk menyimpan perubahan.');
        }

        return 0;
    }
}
