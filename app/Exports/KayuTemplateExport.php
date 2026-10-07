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
                'K-JTI-001',
                'KAYU JATI RST',
                'TEAK',
                'A',
                'PLANK',
                50,
                80,
                1000,
                48,
                78,
                998,
                20,
                'Pieces',
                'SANWIL',
                'RAK-A1',
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
                38,
                58,
                1998,
                15,
                'Pieces',
                'SANWIL',
                'RAK-B2',
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
            'no_rak',
        ];
    }
}
