<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckStockDesync extends Command
{
    protected $signature = 'app:check-stock-desync {--threshold=0.01}';

    protected $description = 'Bandingkan inventories.qty_pcs dengan hitungan ulang dari inventory_logs, laporkan selisih. Read-only, tidak mengubah data apapun.';

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');

        $logNet = DB::table('inventory_logs')
            ->select('item_id', 'warehouse_id')
            ->selectRaw("SUM(CASE WHEN direction = 'IN' THEN qty ELSE 0 END) - SUM(CASE WHEN direction = 'OUT' THEN qty ELSE 0 END) AS net_qty")
            ->selectRaw('COUNT(*) AS log_rows')
            ->groupBy('item_id', 'warehouse_id');

        $mismatches = DB::table('inventories')
            ->joinSub($logNet, 'log_net', function ($join) {
                $join->on('inventories.item_id', '=', 'log_net.item_id')
                    ->on('inventories.warehouse_id', '=', 'log_net.warehouse_id');
            })
            ->join('items', 'items.id', '=', 'inventories.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->selectRaw('items.name AS item_name, items.code AS item_code, warehouses.code AS warehouse_code, inventories.qty_pcs AS cache_qty, log_net.net_qty AS log_net_qty, log_net.log_rows, (inventories.qty_pcs - log_net.net_qty) AS selisih')
            ->havingRaw('ABS(inventories.qty_pcs - log_net.net_qty) > ?', [$threshold])
            ->orderByRaw('ABS(inventories.qty_pcs - log_net.net_qty) DESC')
            ->get();

        if ($mismatches->isEmpty()) {
            $this->info('Tidak ada selisih stok ditemukan.');
            return self::SUCCESS;
        }

        $this->warn("Ditemukan {$mismatches->count()} baris dengan selisih stok:");
        $this->table(
            ['Item', 'Kode', 'Gudang', 'Cache (qty_pcs)', 'Hitung dari Log', 'Selisih', 'Jml Baris Log'],
            $mismatches->map(fn ($row) => [
                $row->item_name,
                $row->item_code,
                $row->warehouse_code,
                $row->cache_qty,
                $row->log_net_qty,
                $row->selisih,
                $row->log_rows,
            ])
        );

        Log::warning('CheckStockDesync menemukan selisih stok', [
            'jumlah_baris' => $mismatches->count(),
            'detail' => $mismatches->toArray(),
        ]);

        return self::SUCCESS;
    }
}
