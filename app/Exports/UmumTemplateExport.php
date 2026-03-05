<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class UmumTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    /**
     * Data contoh untuk template Umum
     * @return array
     */
    public function array(): array
    {
        return [
            [
                'U-001',
                'Paku 5cm',
                'Bahan Baku',
                'Kg',
                50,
                'UMUM',  // gudang_awal
                0,       // harga
            ],
            [
                'U-002',
                'Lem Kayu Crossbond',
                'Bahan Kimia',
                'Liter',
                20,
                'UMUM',  // gudang_awal
                0,       // harga
            ],
            [
                'U-003',
                'Amplas No.80',
                'Bahan Finishing',
                'Lembar',
                100,
                'UMUM',  // gudang_awal
                0,       // harga
            ],
        ];
    }

    /**
     * Header kolom untuk Upload Umum
     * @return array
     */
    public function headings(): array
    {
        return [
            'kode',
            'nama',
            'kategori',
            'satuan',
            'stok_awal',
            'gudang_awal',
            'harga',
        ];
    }
}
