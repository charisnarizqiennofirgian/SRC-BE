<?php

namespace App\Console\Commands;

use App\Models\Item;
use Illuminate\Console\Command;

class BackfillInventoryNaturalWarna extends Command
{
    protected $signature = 'app:backfill-inventory-natural-warna {--dry-run : Tampilkan apa yang akan diubah tanpa menyimpan}';
    protected $description = 'Distribusikan items.qty_natural/qty_warna (global, data lama) ke inventories.qty_natural/qty_warna per gudang secara proporsional berdasarkan qty_pcs. Hanya menyentuh baris inventories yang belum pernah diisi (qty_natural+qty_warna = 0) sehingga aman dijalankan berulang (idempotent) dan tidak akan menimpa data riil per-gudang yang sudah ditulis oleh transaksi Moulding/Mesin/Assembling setelah fix ini berjalan.';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $items = Item::where('type', Item::TYPE_COMPONENT)
            ->where(function ($q) {
                $q->where('qty_natural', '>', 0)->orWhere('qty_warna', '>', 0);
            })
            ->with(['inventories' => function ($q) {
                $q->where('qty_pcs', '>', 0)->with('warehouse:id,name');
            }])
            ->get();

        $this->info("Item component dengan qty_natural/qty_warna > 0: {$items->count()}");

        $processed = 0;
        $skippedAlreadyFilled = 0;
        $skippedNoInventory = 0;

        foreach ($items as $item) {
            $inventories = $item->inventories;

            if ($inventories->isEmpty()) {
                $skippedNoInventory++;
                continue;
            }

            $alreadyFilled = $inventories->sum(fn ($inv) => (float) $inv->qty_natural + (float) $inv->qty_warna);
            if ($alreadyFilled > 0) {
                $skippedAlreadyFilled++;
                continue;
            }

            $totalPcs = (float) $inventories->sum('qty_pcs');
            if ($totalPcs <= 0) {
                $skippedNoInventory++;
                continue;
            }

            $itemNatural = (float) $item->qty_natural;
            $itemWarna   = (float) $item->qty_warna;

            $this->line("{$item->code} ({$item->name}): natural={$itemNatural} warna={$itemWarna} -> {$inventories->count()} baris gudang");

            $sorted = $inventories->sortByDesc('qty_pcs')->values();

            // Baris dengan qty_pcs terbesar menyerap sisa pembulatan; sisanya proporsional
            $assignedNatural = 0.0;
            $assignedWarna   = 0.0;
            $plan = [];

            for ($i = 1; $i < $sorted->count(); $i++) {
                $inv   = $sorted[$i];
                $ratio = (float) $inv->qty_pcs / $totalPcs;
                $natural = round($itemNatural * $ratio, 4);
                $warna   = round($itemWarna * $ratio, 4);
                $assignedNatural += $natural;
                $assignedWarna   += $warna;
                $plan[] = ['inv' => $inv, 'natural' => $natural, 'warna' => $warna];
            }

            $largest = $sorted[0];
            $plan[] = [
                'inv'     => $largest,
                'natural' => round($itemNatural - $assignedNatural, 4),
                'warna'   => round($itemWarna - $assignedWarna, 4),
            ];

            foreach ($plan as $row) {
                $inv = $row['inv'];
                $whName = $inv->warehouse?->name ?? "gudang id={$inv->warehouse_id}";
                $this->line("  -> {$whName} (qty_pcs={$inv->qty_pcs}): natural={$row['natural']} warna={$row['warna']}");

                if (!$dryRun) {
                    $inv->qty_natural = max(0, $row['natural']);
                    $inv->qty_warna   = max(0, $row['warna']);
                    $inv->save();
                }
            }

            $processed++;
        }

        $this->newLine();
        $this->info("Selesai. Item diproses: {$processed}, dilewati (sudah terisi per gudang): {$skippedAlreadyFilled}, dilewati (tanpa inventory): {$skippedNoInventory}.");

        if ($dryRun) {
            $this->warn('Ini masih dry-run. Jalankan tanpa --dry-run untuk menyimpan perubahan.');
        }

        return 0;
    }
}
