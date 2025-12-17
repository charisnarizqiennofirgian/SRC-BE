<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class KomponenTemplateExport implements FromArray, WithHeadings
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
                'CMP-001',
                'Komponen Contoh',
                'Komponen',
                'PCS',
                100,   // p (mm)
                50,    // l (mm)
                20,    // t (mm)
                500,   // stok_awal
            ],
        ];
    }
}
