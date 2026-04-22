<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class JeblosanTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        return [
            ['JBL-001', 'JEBLOSAN JATI', 25, 270, 2900, 0.004860, 10, 'Pieces', 'SAWMILL'],
            ['JBL-002', 'JEBLOSAN JATI', 30, 270, 2900, 0.005832, 5,  'Pieces', 'SAWMILL'],
        ];
    }

    public function headings(): array
    {
        return [
            'kode_barang',
            'nama_dasar',
            'tebal_mm',
            'lebar_mm',
            'panjang_mm',
            'm3_per_pcs',
            'stok_awal',
            'satuan',
            'gudang',
        ];
    }
}