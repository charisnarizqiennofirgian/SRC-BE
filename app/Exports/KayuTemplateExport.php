<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class KayuTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        return [
            [
                'K-JTI-001',      // kode_barang
                'KAYU JATI RST',  // nama_dasar
                'TEAK',           // jenis
                'A',              // kualitas
                'PLANK',          // bentuk
                50,               // tebal_mm
                80,               // lebar_mm
                1000,             // panjang_mm
                48,               // cutting_tebal_mm
                78,               // cutting_lebar_mm
                998,              // cutting_panjang_mm
                20,               // stok_awal
                'Pieces',         // satuan (harus sama dengan master Unit)
                'SANWIL',         // gudang (kode gudang contoh)
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
                38,               // cutting_tebal_mm
                58,               // cutting_lebar_mm
                1998,             // cutting_panjang_mm
                15,
                'Pieces',
                'SANWIL',
            ],
        ];
    }

    public function headings(): array
    {
        return [
            'kode_barang',
            'nama_dasar',
            'jenis',
            'kualitas',
            'bentuk',
            'tebal_mm',
            'lebar_mm',
            'panjang_mm',
            'cutting_tebal_mm',
            'cutting_lebar_mm',
            'cutting_panjang_mm',
            'stok_awal',
            'satuan',
            'gudang',
        ];
    }
}
