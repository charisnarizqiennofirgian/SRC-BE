<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class KayuLogTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'Kode',
            'Kategori',
            'Satuan',
            'Gudang',
            'Tanggal Terima',
            'TPK',
            'Jenis Kayu',
            'NO SKSHHK',
            'No Kapling',
            'Panjang (m)',
            'Diameter (cm)',
            'Stok',
            'Kubikasi (m³)',
            'Mutu',
        ];
    }

    public function array(): array
    {
        return [
            [
                'KLG-001',
                'Kayu Log',
                'Batang',
                'LOG',
                '2023-01-01',
                'TPK-01',
                'Jati',
                'SKSHHK-001',
                'KPL-001',
                4.0, // Panjang (m)
                40,  // Diameter (cm)
                10,
                0.502,
                'A',
            ],
        ];
    }
}
