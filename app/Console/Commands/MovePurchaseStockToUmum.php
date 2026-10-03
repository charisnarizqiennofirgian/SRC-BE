<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MovePurchaseStockToUmum extends Command
{
    protected $signature = 'app:move-purchase-stock-to-umum
        {--execute : Simpan perubahan (tanpa ini hanya dry-run)}
        {--include-mixed : Ikut pindahkan item yang di BUFFER juga punya stok masuk selain dari pembelian}';

    protected $description = 'Pindahkan stok barang hasil PO non-kayu (operasional, karton, dll) yang dulu salah masuk Gudang Pembahanan (BUFFER) ke Gudang Bahan Operasional (UMUM). Item yang juga pernah diterima dari PO kayu dilewati. Idempotent.';

    public function handle()
    {
        $execute = (bool) $this->option('execute');
        $includeMixed = (bool) $this->option('include-mixed');

        if (!$execute) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $buffer = Warehouse::where('code', 'BUFFER')->first();
        $umum = Warehouse::where('code', 'UMUM')->first();
        if (!$buffer || !$umum) {
            $this->error('Gudang BUFFER atau UMUM tidak ditemukan. Dibatalkan.');
            return 1;
        }

        $receivedByType = DB::table('goods_receipt_details as d')
            ->join('goods_receipts as g', 'g.id', '=', 'd.goods_receipt_id')
            ->join('purchase_orders as p', 'p.id', '=', 'g.purchase_order_id')
            ->whereNull('g.deleted_at')
            ->select('d.item_id', 'p.type')
            ->distinct()
            ->get()
            ->groupBy('item_id');

        $kayuItemIds = [];
        $candidateIds = [];
        foreach ($receivedByType as $itemId => $rows) {
            if ($rows->pluck('type')->contains('kayu')) {
                $kayuItemIds[] = $itemId;
            } else {
                $candidateIds[] = $itemId;
            }
        }

        $stocks = Inventory::where('warehouse_id', $buffer->id)
            ->whereIn('item_id', $candidateIds)
            ->where('qty_pcs', '>', 0)
            ->get()
            ->groupBy('item_id');

        $otherInTypes = InventoryLog::where('warehouse_id', $buffer->id)
            ->whereIn('item_id', $stocks->keys())
            ->where('direction', 'IN')
            ->where('transaction_type', '!=', 'PURCHASE')
            ->select('item_id', 'transaction_type')
            ->distinct()
            ->get()
            ->groupBy('item_id');

        $this->info('Item dari PO non-kayu dengan stok di BUFFER: ' . $stocks->count());
        $this->info('Item yang juga diterima dari PO kayu (dilewati): ' . count($kayuItemIds));

        $moved = 0;
        $skipped = [];

        foreach ($stocks as $itemId => $rows) {
            $item = Item::withTrashed()->find($itemId, ['id', 'code', 'name']);
            $label = ($item->code ?? '-') . ' / ' . ($item->name ?? "item_id={$itemId}");

            if (isset($otherInTypes[$itemId]) && !$includeMixed) {
                $skipped[] = $label . ' [' . $otherInTypes[$itemId]->pluck('transaction_type')->implode(', ') . ']';
                continue;
            }

            foreach ($rows as $row) {
                $qty = (float) $row->qty_pcs;
                $gradeText = $row->grade ? " (Grade {$row->grade})" : '';
                $this->line("  {$label}{$gradeText}: pindah {$qty}, BUFFER -> UMUM");

                if (!$execute) {
                    continue;
                }

                DB::transaction(function () use ($row, $umum, $buffer) {
                    $src = Inventory::lockForUpdate()->find($row->id);
                    $qty = (float) $src->qty_pcs;
                    if ($qty <= 0) {
                        return;
                    }
                    $m3 = (float) $src->qty_m3;
                    $nat = (float) $src->qty_natural;
                    $warna = (float) $src->qty_warna;

                    $src->update(['qty_pcs' => 0, 'qty_m3' => 0, 'qty_natural' => 0, 'qty_warna' => 0]);

                    $dst = Inventory::where('warehouse_id', $umum->id)
                        ->where('item_id', $src->item_id)
                        ->where('grade_key', $src->grade ?? '')
                        ->lockForUpdate()
                        ->first();
                    if (!$dst) {
                        $dst = Inventory::create([
                            'warehouse_id' => $umum->id,
                            'item_id'      => $src->item_id,
                            'grade'        => $src->grade,
                            'qty_pcs'      => 0,
                            'qty_m3'       => 0,
                            'qty_natural'  => 0,
                            'qty_warna'    => 0,
                        ]);
                    }
                    $dst->update([
                        'qty_pcs'     => (float) $dst->qty_pcs + $qty,
                        'qty_m3'      => (float) $dst->qty_m3 + $m3,
                        'qty_natural' => (float) $dst->qty_natural + $nat,
                        'qty_warna'   => (float) $dst->qty_warna + $warna,
                    ]);

                    $now = now();
                    $notes = 'Koreksi gudang: barang PO non-kayu dipindah dari Gudang Pembahanan ke Gudang Bahan Operasional (app:move-purchase-stock-to-umum)';
                    foreach ([[$buffer->id, 'OUT', InventoryLog::TYPE_TRANSFER_OUT], [$umum->id, 'IN', InventoryLog::TYPE_TRANSFER_IN]] as [$warehouseId, $direction, $type]) {
                        InventoryLog::create([
                            'date'             => $now->toDateString(),
                            'time'             => $now->toTimeString(),
                            'item_id'          => $src->item_id,
                            'warehouse_id'     => $warehouseId,
                            'qty'              => $qty,
                            'qty_m3'           => $m3,
                            'direction'        => $direction,
                            'transaction_type' => $type,
                            'notes'            => $notes,
                            'grade'            => $src->grade,
                        ]);
                    }
                });
            }

            $moved++;
        }

        if ($skipped) {
            $this->newLine();
            $this->warn('Dilewati karena di BUFFER juga ada stok masuk selain pembelian (cek manual, atau jalankan dengan --include-mixed):');
            foreach ($skipped as $line) {
                $this->line('  - ' . $line);
            }
        }

        $this->newLine();
        $this->info("Selesai. Item dipindah: {$moved}. Dilewati: " . count($skipped) . '.');
        if (!$execute) {
            $this->warn('Ini masih dry-run. Jalankan dengan --execute untuk menyimpan perubahan.');
        }

        return 0;
    }
}
