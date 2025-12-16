<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class KayuLogTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'kode_item',
            'nama_item',
            'kategori',     // nama kategori dari master
            'satuan',       // nama satuan dari master
            'gudang',       // kode gudang, mis: LOG
            'qty_batang',
            'diameter_cm',
            'panjang_cm',
            'jenis_kayu',
            'kubikasi_m3',
        ];
    }

    public function array(): array
    {
        return [
            [
                'KLG-001',
                'Log Jati 40cm',
                'Kayu Log',
                'Batang',
                'LOG',
                10,
                40,
                400,
                'Jati',
                0,
            ],
        ];
    }
}
