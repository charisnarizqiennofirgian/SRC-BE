<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Console\Command;

class FixConsumableDisplayStockDesync extends Command
{
    protected $signature = 'app:fix-consumable-display-stock-desync {--dry-run : Tampilkan apa yang akan diubah tanpa menyimpan}';

    protected $description = 'Sinkronkan items.stock (cache, ditampilkan di menu Pemakaian Bahan) dengan SUM(inventories.qty_pcs) (stok fisik riil per gudang, dipakai validasi saat simpan) untuk SEMUA item kategori Bahan Operasional/Karton Box yang desync, kedua arah. Arah koreksi per item ditentukan dari inventory_logs NET (IN-OUT): kalau NET cocok ke salah satu sisi, sisi yang lain diperbaiki mengikuti; item yang NET tidak cocok ke keduanya dilewati & dilaporkan (dicoba dijelaskan lewat stock_movements, tapi tidak di-auto-fix). Idempotent.';

    private const CATEGORY_WAREHOUSE_FALLBACK = [
        'Bahan Operasional' => 'UMUM',
        'Karton Box'        => 'PACKING',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $catIds = Category::whereIn('name', array_keys(self::CATEGORY_WAREHOUSE_FALLBACK))->pluck('id', 'name');
        if ($catIds->isEmpty()) {
            $this->error('Kategori Bahan Operasional / Karton Box tidak ditemukan. Dibatalkan.');
            return 1;
        }

        $fallbackWarehouseIds = [];
        foreach (self::CATEGORY_WAREHOUSE_FALLBACK as $catName => $whCode) {
            $wh = Warehouse::where('code', $whCode)->first();
            if ($wh) {
                $fallbackWarehouseIds[$catName] = $wh->id;
            }
        }

        $items = Item::whereIn('category_id', $catIds->values())
            ->with('category:id,name')
            ->whereNull('deleted_at')
            ->get(['id', 'code', 'name', 'stock', 'category_id']);

        $this->line("Item Bahan Operasional/Karton Box diperiksa: {$items->count()}");

        $cacheAdjusted = [];
        $inventoryToppedUp = [];
        $inventoryReduced = [];
        $skipped = [];

        foreach ($items as $item) {
            $cache = (float) $item->stock;
            $invSum = (float) Inventory::where('item_id', $item->id)->sum('qty_pcs');

            if (abs($cache - $invSum) <= 0.001) {
                continue;
            }

            $logIn = (float) InventoryLog::where('item_id', $item->id)->where('direction', 'IN')->sum('qty');
            $logOut = (float) InventoryLog::where('item_id', $item->id)->where('direction', 'OUT')->sum('qty');
            $logNet = $logIn - $logOut;

            $matchesInventory = abs($logNet - $invSum) < 0.001;
            $matchesCache = abs($logNet - $cache) < 0.001;

            if ($matchesInventory && !$matchesCache) {
                // inventory_logs membuktikan SUM(inventories) yang benar -> items.stock ikut ke situ
                $cacheAdjusted[] = [$item->code, $item->name, $cache, $invSum];
                if (!$dryRun) {
                    $item->stock = $invSum;
                    $item->save();
                }
                continue;
            }

            if ($matchesCache && !$matchesInventory) {
                // inventory_logs membuktikan items.stock yang benar -> inventories yang disesuaikan
                if ($cache > $invSum) {
                    $this->topUpInventory($item, $cache, $invSum, $fallbackWarehouseIds, $inventoryToppedUp, $dryRun);
                } else {
                    $this->reduceInventory($item, $cache, $invSum, $inventoryReduced, $dryRun);
                }
                continue;
            }

            // NET tidak cocok ke keduanya -> coba jelaskan lewat stock_movements (info saja, tidak auto-fix)
            $movements = class_exists(StockMovement::class)
                ? StockMovement::where('item_id', $item->id)->get()
                : collect();
            $movementNote = $movements->isNotEmpty()
                ? 'ada ' . $movements->count() . ' baris stock_movements — cek manual'
                : 'NET tidak cocok ke keduanya, tidak ada stock_movements';

            $skipped[] = [$item->code, $item->name, $cache, $invSum, $logNet, $movementNote];
        }

        $this->printSection('items.stock disesuaikan (ikut SUM(inventories), dibuktikan log)', $cacheAdjusted, ['Kode', 'Nama', 'stock lama', 'stock baru'], $dryRun);
        $this->printSection('inventories di-top-up (items.stock dibuktikan log, inventories kurang)', $inventoryToppedUp, ['Kode', 'Nama', 'items.stock', 'SUM(inv) lama', 'warehouse_id tujuan', 'ditambahkan'], $dryRun);
        $this->printSection('inventories dikurangi (items.stock dibuktikan log, inventories lebih)', $inventoryReduced, ['Kode', 'Nama', 'items.stock', 'SUM(inv) lama', 'detail pengurangan per baris'], $dryRun);

        if ($skipped) {
            $this->newLine();
            $this->warn('DILEWATI (perlu cek manual, arah tidak jelas dari inventory_logs): ' . count($skipped));
            $this->table(['Kode', 'Nama', 'items.stock', 'SUM(inv)', 'log NET', 'Catatan'], $skipped);
        }

        if (!$cacheAdjusted && !$inventoryToppedUp && !$inventoryReduced && !$skipped) {
            $this->info('Tidak ada item yang desync.');
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Jalankan tanpa --dry-run untuk menyimpan.');
        }

        return 0;
    }

    private function topUpInventory(Item $item, float $cache, float $invSum, array $fallbackWarehouseIds, array &$log, bool $dryRun): void
    {
        $target = Inventory::where('item_id', $item->id)
            ->orderByDesc('qty_pcs')
            ->orderBy('created_at')
            ->first();

        $shortfall = round($cache - $invSum, 4);

        if ($target) {
            $log[] = [$item->code, $item->name, $cache, $invSum, $target->warehouse_id, $shortfall];
            if (!$dryRun) {
                $target->increment('qty_pcs', $shortfall);
            }
            return;
        }

        $catName = $item->category?->name;
        $fallbackWhId = $fallbackWarehouseIds[$catName] ?? null;
        if (!$fallbackWhId) {
            return;
        }

        $log[] = [$item->code, $item->name, $cache, $invSum, "{$fallbackWhId} (baru)", $shortfall];
        if (!$dryRun) {
            Inventory::create([
                'item_id' => $item->id,
                'warehouse_id' => $fallbackWhId,
                'qty_pcs' => $shortfall,
                'qty_natural' => 0,
                'qty_warna' => 0,
            ]);
        }
    }

    private function reduceInventory(Item $item, float $cache, float $invSum, array &$log, bool $dryRun): void
    {
        $excess = round($invSum - $cache, 4);
        $rows = Inventory::where('item_id', $item->id)
            ->orderByDesc('qty_pcs')
            ->get();

        $remaining = $excess;
        $detail = [];

        foreach ($rows as $row) {
            if ($remaining <= 0.0001) {
                break;
            }
            $take = min($remaining, (float) $row->qty_pcs);
            if ($take <= 0) {
                continue;
            }
            $detail[] = "gudang {$row->warehouse_id}: -{$take}";
            $remaining -= $take;

            if (!$dryRun) {
                $row->decrement('qty_pcs', $take);
            }
        }

        $log[] = [$item->code, $item->name, $cache, $invSum, implode(', ', $detail) . ($remaining > 0.0001 ? " (SISA {$remaining} tidak terserap!)" : '')];
    }

    private function printSection(string $title, array $rows, array $headers, bool $dryRun): void
    {
        if (!$rows) {
            return;
        }
        $this->newLine();
        $this->info(($dryRun ? 'AKAN diproses — ' : 'Diproses — ') . $title . ': ' . count($rows));
        $this->table($headers, $rows);
    }
}
