<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class KayuTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    /**
    * @return array
    */
    public function array(): array
    {
        // Ini hanya data contoh untuk template
        return [
            [
                'K-JTI-001',
                'KAYU JATI RST [A]',
                '50',
                '80',
                '100',
                '20'
            ],
            [
                'K-MRN-001',
                'KAYU MERANTI',
                '40',
                '60',
                '2000',
                '15'
            ]
        ];
    }

    /**
    * @return array
    */
    public function headings(): array
    {
        // Ini adalah header yang akan dibaca oleh "mesin pintar" (KayuStockImport)
        return [
            'kode_barang',
            'nama_dasar',
            'tebal_mm',
            'lebar_mm',
            'panjang_mm',
            'stok_awal'
        ];
    }
}