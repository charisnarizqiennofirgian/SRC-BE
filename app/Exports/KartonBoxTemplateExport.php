<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class KartonBoxTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'kode',
            'nama',
            'kategori',
            'satuan',
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
                'Karton Box 1',
                'Karton Box',
                'PCS',
                500,
                400,
                300,
                100,
            ],
        ];
    }
}
