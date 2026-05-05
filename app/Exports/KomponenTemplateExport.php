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
                45,         // t
                50,         // l
                800,        // p
                2,          // qty_set
                10,         // qty_natural
                5,          // qty_warna
                0.0270,     // m3_total (isi ini kalau dari Moulding)
                0.0000,     // m3_natural (kosong)
                0.0000,     // m3_warna (kosong)
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
                0.0000,     // m3_total (kosong)
                0.0160,     // m3_natural (isi ini kalau dari Mesin)
                0.0080,     // m3_warna (isi ini kalau dari Mesin)
                'MESIN',
            ],
        ];
    }
}