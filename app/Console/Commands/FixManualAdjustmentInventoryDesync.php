<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixManualAdjustmentInventoryDesync extends Command
{
    protected $signature = 'app:fix-manual-adjustment-inventory-desync {--dry-run : Tampilkan apa yang akan diubah tanpa menyimpan}';

    protected $description = 'Terapkan ke inventories penyesuaian manual (menu Penyesuaian Stok) Bahan Operasional/Karton Box yang dulu hanya mengubah items.stock. Hanya item yang selisih items.stock - SUM(inventories) persis sama dengan total penyesuaian manual sejak opname terakhir item itu. Idempotent.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $umumId = Warehouse::where('code', 'UMUM')->value('id');
        if (!$umumId) {
            $this->error('Gudang UMUM tidak ditemukan. Dibatalkan.');
            return 1;
        }

        $catIds = Category::where(function ($q) {
            $q->orWhereRaw('LOWER(name) LIKE ?', ['%bahan%'])
              ->orWhereRaw('LOWER(name) LIKE ?', ['%karton%']);
        })->pluck('id');

        $items = Item::whereIn('category_id', $catIds)->get(['id', 'code', 'name', 'stock']);
        $fixed = [];
        $skipped = [];

        foreach ($items as $item) {
            $invSum = (float) Inventory::where('item_id', $item->id)->sum('qty_pcs');
            $diff = round((float) $item->stock - $invSum, 4);
            if (abs($diff) < 0.0001) {
                continue;
            }

            $cutoff = InventoryLog::where('item_id', $item->id)
                ->where('transaction_type', 'OPNAME')
                ->max('created_at');

            $appliedMovementIds = InventoryLog::where('item_id', $item->id)
                ->where('reference_type', 'StockAdjustment')
                ->pluck('reference_id');

            $adjSum = (float) DB::table('stock_movements')
                ->where('item_id', $item->id)
                ->where('notes', 'like', 'Penyesuaian manual:%')
                ->when($cutoff, fn ($q) => $q->where('created_at', '>', $cutoff))
                ->whereNotIn('id', $appliedMovementIds)
                ->sum('quantity');

            if (abs($adjSum) < 0.0001) {
                continue;
            }

            $label = "{$item->code} | {$item->name} | items.stock=" . (float) $item->stock . " inventories={$invSum} penyesuaian={$adjSum}";

            if (abs($adjSum - $diff) >= 0.0001 || ($diff < 0 && $invSum < -$diff)) {
                $skipped[] = $label;
                continue;
            }

            $fixed[] = $label;
            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($item, $diff, $umumId) {
                if ($diff > 0) {
                    $inventory = Inventory::where('item_id', $item->id)->orderByDesc('qty_pcs')->lockForUpdate()->first()
                        ?? Inventory::create(['warehouse_id' => $umumId, 'item_id' => $item->id, 'qty_pcs' => 0, 'qty_m3' => 0]);
                    $inventory->increment('qty_pcs', $diff);
                    $this->log($item, $inventory->warehouse_id, $diff);
                    return;
                }

                $remaining = -$diff;
                $rows = Inventory::where('item_id', $item->id)->where('qty_pcs', '>', 0)
                    ->orderByDesc('qty_pcs')->lockForUpdate()->get();
                foreach ($rows as $row) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $take = min($remaining, (float) $row->qty_pcs);
                    $row->decrement('qty_pcs', $take);
                    $this->log($item, $row->warehouse_id, -$take);
                    $remaining -= $take;
                }
            });
        }

        $this->info(($dryRun ? 'Akan diperbaiki' : 'Diperbaiki') . ': ' . count($fixed));
        foreach ($fixed as $line) {
            $this->line('  ' . $line);
        }
        $this->warn('Dilewati (selisih tidak cocok dengan penyesuaian manual, cek manual): ' . count($skipped));
        foreach ($skipped as $line) {
            $this->line('  ' . $line);
        }

        return 0;
    }

    private function log(Item $item, int $warehouseId, float $qty): void
    {
        InventoryLog::create([
            'date'             => now()->toDateString(),
            'time'             => now()->toTimeString(),
            'item_id'          => $item->id,
            'warehouse_id'     => $warehouseId,
            'qty'              => abs($qty),
            'direction'        => $qty > 0 ? 'IN' : 'OUT',
            'transaction_type' => InventoryLog::TYPE_ADJUSTMENT,
            'reference_type'   => 'StockAdjustmentFix',
            'reference_id'     => $item->id,
            'reference_number' => 'KOREKSI-ADJ-' . $item->code,
            'notes'            => 'Koreksi: penyesuaian manual sebelumnya hanya mengubah items.stock, belum masuk stok gudang (app:fix-manual-adjustment-inventory-desync)',
        ]);
    }
}
