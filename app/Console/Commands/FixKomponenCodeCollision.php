<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

class FixKomponenCodeCollision extends Command
{
    protected $signature = 'app:fix-komponen-code-collision';
    protected $description = 'Ganti kode item Komponen yang salah menimpa kode Produk Jadi (insiden 2026-07-02), supaya kode aslinya bebas dipakai lagi. Aman dijalankan berulang kali (idempotent).';

    // Kode ASLI (sebelum insiden) -> match by kode SEKARANG (sebelum di-rename)
    // Kalau command ini sudah pernah dijalankan, kode ini sudah tidak ada lagi (sudah -KOMP)
    // sehingga baris itu otomatis dilewati.
    private array $affectedCodes = [
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
        $this->info('Cek item Komponen yang kodenya bentrok dengan Produk Jadi asli...');

        $fixed = 0;
        $skipped = 0;

        DB::transaction(function () use (&$fixed, &$skipped) {
            foreach ($this->affectedCodes as $code) {
                $item = Item::where('code', $code)->first();

                if (!$item) {
                    $this->line("  - Kode '{$code}' tidak ditemukan (sudah diperbaiki sebelumnya, atau memang tidak ada di DB ini). Dilewati.");
                    $skipped++;
                    continue;
                }

                if ($item->type !== Item::TYPE_COMPONENT) {
                    $this->warn("  ! Kode '{$code}' (id={$item->id}, nama='{$item->name}') BUKAN type=component (type='{$item->type}'). Dilewati demi keamanan — cek manual.");
                    $skipped++;
                    continue;
                }

                $newCode = $code . '-KOMP';
                if (Item::where('code', $newCode)->exists()) {
                    $newCode = $code . '-KOMP2';
                }

                $item->code = $newCode;
                $item->save();

                $this->info("  OK id={$item->id} nama='{$item->name}' : {$code} -> {$newCode}");
                $fixed++;
            }
        });

        $this->newLine();
        $this->info("Selesai. Diperbaiki: {$fixed}, Dilewati: {$skipped}.");

        if ($fixed > 0) {
            $this->newLine();
            $this->info('Kode asli sekarang sudah bebas — silakan buat ulang item Produk Jadi yang benar pakai kode asli itu kalau perlu.');
        }

        return 0;
    }
}
