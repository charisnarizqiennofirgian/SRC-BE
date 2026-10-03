<?php

namespace App\Console\Commands;

use App\Models\StockOpname;
use App\Services\StockOpnameService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CekStockOpname extends Command
{
    protected $signature = 'app:cek-stock-opname
        {--sync : Tambahkan barang berstok yang belum ada ke opname draft (sama dengan tombol Sinkron Item)}
        {--item=* : Kode barang yang ingin dicek, boleh lebih dari satu}';

    protected $description = 'Cek kelengkapan stok opname draft: tampilkan barang berstok di gudang opname yang belum masuk daftar, dan opsional sinkronkan.';

    public function handle()
    {
        $this->info('=== DAFTAR OPNAME ===');
        foreach (StockOpname::orderBy('id')->get() as $o) {
            $rows = DB::table('stock_opname_details')->where('stock_opname_id', $o->id);
            $wh = DB::table('warehouses')->where('id', $o->warehouse_id)->value('code');
            $this->line("{$o->opname_number} (id {$o->id}) gudang {$wh} status {$o->status}: "
                . (clone $rows)->count() . ' baris, dihitung ' . (clone $rows)->whereNotNull('real_qty_pcs')->count());
        }

        $drafts = StockOpname::where('status', StockOpname::STATUS_DRAFT)->orderBy('id')->get();
        if ($drafts->isEmpty()) {
            $this->warn('Tidak ada opname draft.');
            return 0;
        }

        foreach ($drafts as $opname) {
            $this->newLine();
            $this->info("=== CEK {$opname->opname_number} ===");

            foreach ((array) $this->option('item') as $code) {
                $itemId = DB::table('items')->where('code', $code)->value('id');
                if (!$itemId) {
                    $this->line("{$code}: kode tidak ditemukan di master barang");
                    continue;
                }
                $stocks = DB::table('inventories as i')
                    ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')
                    ->where('i.item_id', $itemId)
                    ->where('i.qty_pcs', '!=', 0)
                    ->get(['w.code', 'i.qty_pcs'])
                    ->map(fn ($s) => $s->code . ':' . ($s->qty_pcs + 0))
                    ->implode(', ');
                $inOpname = DB::table('stock_opname_details')
                    ->where('stock_opname_id', $opname->id)
                    ->where('item_id', $itemId)
                    ->exists();
                $this->line("{$code}: stok [" . ($stocks ?: 'kosong') . '], di opname: ' . ($inOpname ? 'YA' : 'TIDAK'));
            }

            $missing = $this->missingRows($opname);
            $this->line('Barang berstok di gudang ini yang BELUM ada di opname: ' . $missing->count());
            foreach ($missing->take(15) as $m) {
                $this->line("   {$m->code} | {$m->name} | " . ($m->qty_pcs + 0));
            }

            if ($this->option('sync')) {
                $result = DB::transaction(fn () => app(StockOpnameService::class)
                    ->syncWarehouse(StockOpname::lockForUpdate()->find($opname->id)));
                $this->info("SINKRON: {$result['added']} item ditambahkan, {$result['refreshed']} stok sistem diperbarui. Total baris sekarang: "
                    . DB::table('stock_opname_details')->where('stock_opname_id', $opname->id)->count());
            } elseif ($missing->isNotEmpty()) {
                $this->warn('Jalankan dengan --sync untuk menambahkan barang di atas ke opname.');
            }
        }

        return 0;
    }

    private function missingRows(StockOpname $opname)
    {
        return DB::table('inventories as i')
            ->join('items as it', 'it.id', '=', 'i.item_id')
            ->whereNull('it.deleted_at')
            ->where('i.warehouse_id', $opname->warehouse_id)
            ->where(function ($q) {
                $q->where('i.qty_pcs', '>', 0)->orWhere('i.qty_natural', '>', 0)->orWhere('i.qty_warna', '>', 0);
            })
            ->whereNotExists(function ($q) use ($opname) {
                $q->from('stock_opname_details as d')
                    ->whereColumn('d.item_id', 'i.item_id')
                    ->whereRaw("COALESCE(d.grade, '') = COALESCE(i.grade, '')")
                    ->where('d.stock_opname_id', $opname->id);
            })
            ->get(['it.code', 'it.name', 'i.qty_pcs']);
    }
}
