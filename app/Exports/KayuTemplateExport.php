<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class KayuTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        // Contoh baris 1
        return [
            [
                'K-JTI-001',           // kode_barang
                'KAYU JATI RST',       // nama_dasar
                'TEAK',                // jenis
                'A',                   // kualitas
                'PLANK',               // bentuk
                50,                    // tebal_mm
                80,                    // lebar_mm
                1000,                  // panjang_mm
                20,                    // stok_awal
            ],
            [
                'K-MRN-001',
                'KAYU MERANTI',
                'MERANTI',
                'B',
                'PLANK',
                40,
                60,
                2000,
                15,
            ],
        ];
    }

    public function headings(): array
    {
        // Harus sama persis dengan yang dibaca di KayuStockImport
        return [
            'kode_barang',
            'nama_dasar',
            'jenis',
            'kualitas',
            'bentuk',
            'tebal_mm',
            'lebar_mm',
            'panjang_mm',
            'stok_awal',
        ];
    }
}
