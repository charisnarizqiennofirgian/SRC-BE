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
                'U-001',              // kode
                'Paku 5cm',           // nama
                'Bahan Baku',         // kategori
                'Kg',                 // satuan
                50,                   // stok_awal (angka)
                             // gudang (kode warehouse)
            ],
            [
                'U-002',
                'Lem Kayu Crossbond',
                'Bahan Kimia',
                'Liter',
                20,
                'SANWIL',
            ],
            [
                'U-003',
                'Amplas No.80',
                'Bahan Finishing',
                'Lembar',
                100,
                
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
            
        ];
    }
}
