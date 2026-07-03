<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

class RelinkSalesOrderToFixedProdukJadi extends Command
{
    protected $signature = 'app:relink-produk-jadi-from-komponen';
    protected $description = 'Sambungkan ulang sales_order_details DAN production_order_details yang item_id-nya nyasar ke Komponen hasil insiden 2026-07-02, ke item Produk Jadi pengganti yang benar (dicari berdasarkan kode, bukan ID — aman dijalankan di database manapun). Jalankan SETELAH app:fix-komponen-code-collision & upload ulang Produk Jadi selesai.';

    // Kode asli (yang sekarang dipakai ulang oleh Produk Jadi baru)
    private array $originalCodes = [
        '015M24T1',
        '047M18G00',
        '260M20G00',
        '130M16G00',
        '001O25Q00',
        '174M16G00',
        '025M20T1S-SEM-',
        'CBLT',
        '086M19T1',
        '091M16G04',
    ];

    public function handle()
    {
        $totalRelinked = 0;

        $this->info('=== 1. sales_order_details ===');
        foreach ($this->originalCodes as $code) {
            $oldItem = Item::where('code', $code . '-KOMP')->first();
            if (!$oldItem) {
                $this->line("  - Kode lama '{$code}-KOMP' tidak ditemukan. Dilewati.");
                continue;
            }

            $newItem = Item::where('code', $code)->where('type', Item::TYPE_FINISHED_GOOD)->first();
            if (!$newItem) {
                $this->warn("  ! Kode '{$code}' belum ada item Produk Jadi pengganti (type=finished_good). Pastikan upload Produk Jadi sudah selesai. Dilewati untuk kode ini.");
                continue;
            }

            $affectedDetails = DB::table('sales_order_details as sd')
                ->join('sales_orders as so', 'so.id', '=', 'sd.sales_order_id')
                ->where('sd.item_id', $oldItem->id)
                ->select('sd.id', 'so.so_number', 'sd.item_name', 'sd.quantity_shipped')
                ->get();

            foreach ($affectedDetails as $detail) {
                if ((float) $detail->quantity_shipped > 0) {
                    $this->error("  ! SO={$detail->so_number} detail_id={$detail->id} SUDAH ADA quantity_shipped > 0 — TIDAK di-relink otomatis, cek manual!");
                    continue;
                }

                DB::table('sales_order_details')->where('id', $detail->id)->update(['item_id' => $newItem->id]);
                $this->info("  OK SO={$detail->so_number} detail_id={$detail->id} '{$detail->item_name}' : item_id {$oldItem->id} -> {$newItem->id}");
                $totalRelinked++;
            }
        }

        $this->newLine();
        $this->info('=== 2. production_order_details (dipakai menu Produksi saat pilih PO) ===');
        foreach ($this->originalCodes as $code) {
            $oldItem = Item::where('code', $code . '-KOMP')->first();
            if (!$oldItem) {
                continue;
            }

            $newItem = Item::where('code', $code)->where('type', Item::TYPE_FINISHED_GOOD)->first();
            if (!$newItem) {
                continue;
            }

            $affectedDetails = DB::table('production_order_details as pd')
                ->join('production_orders as po', 'po.id', '=', 'pd.production_order_id')
                ->where('pd.item_id', $oldItem->id)
                ->select('pd.id', 'po.po_number', 'pd.qty_planned', 'pd.qty_produced')
                ->get();

            foreach ($affectedDetails as $detail) {
                if ((float) $detail->qty_produced > 0) {
                    $this->error("  ! PO={$detail->po_number} detail_id={$detail->id} SUDAH ADA qty_produced > 0 — TIDAK di-relink otomatis, cek manual!");
                    continue;
                }

                DB::table('production_order_details')->where('id', $detail->id)->update(['item_id' => $newItem->id]);
                $this->info("  OK PO={$detail->po_number} detail_id={$detail->id} qty_planned={$detail->qty_planned} : item_id {$oldItem->id} -> {$newItem->id}");
                $totalRelinked++;
            }
        }

        $this->newLine();
        $this->info("Selesai. Total baris di-relink: {$totalRelinked}.");

        return 0;
    }
}
