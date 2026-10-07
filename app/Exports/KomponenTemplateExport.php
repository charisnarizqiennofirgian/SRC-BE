<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class KomponenTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function headings(): array
    {
        return [
            'kode',
            'nama_komponen',
            'kategori',
            'satuan',
            'buyer',
            'nama_produk',
            'jenis_kayu',
            't',
            'l',
            'p',
            'qty_set',
            'qty_natural',
            'qty_warna',
            'm3_total',
            'm3_natural',
            'm3_warna',
            'gudang',
        ];
    }

    public function array(): array
    {
        return [
            [
                'CMP-001',
                'KAKI DEPAN KANAN',
                'Komponen',
                'PCS',
                'ETHIMO',
                'PATIO TOP DINING TABLE',
                'JATI',
                45,
                50,
                800,
                2,
                10,
                5,
                0.0270,
                0.0000,
                0.0000,
                'MOULDING',
            ],
            [
                'CMP-002',
                'KAKI BELAKANG KIRI',
                'Komponen',
                'PCS',
                'ETHIMO',
                'PATIO TOP DINING TABLE',
                'JATI',
                45,
                50,
                900,
                2,
                8,
                4,
                0.0000,
                0.0160,
                0.0080,
                'MESIN',
            ],
        ];
    }
}