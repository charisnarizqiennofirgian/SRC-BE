<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class KartonBoxTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function headings(): array
    {
        return [
            'kode',
            'nama',
            'kategori',
            'satuan',
            'buyer_name',
            'model',
            'jenis_karton',
            'kualitas',
            'p',
            'l',
            't',
            'stok_awal',
        ];
    }

    public function array(): array
    {
        return [
            [
                'KRT-001',
                'BOX U. AGAVE 90X90',
                'Karton Box',
                'PCS',
                'ETHIMO',
                'AGAVE',
                'RST',
                'A',
                920,
                510,
                130,
                100,
            ],
            [
                'KRT-002',
                'BOX U. AGAVE D 110',
                'Karton Box',
                'PCS',
                'ETHIMO',
                'AGAVE D',
                'RST',
                'A',
                1140,
                1140,
                60,
                50,
            ],
        ];
    }
}