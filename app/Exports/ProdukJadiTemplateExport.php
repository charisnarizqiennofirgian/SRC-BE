<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ProdukJadiTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    /**
     * Data contoh untuk template Produk Jadi
     * @return array
     */
    public function array(): array
    {
        return [
            [
                'PJ-001',
                'KILT DINING',
                '10',
                '27.00',
                '42.00',
                '0.0099',
                '0.045',
            ],
            [
                'PJ-002',
                'CANAL SERIES',
                '5',
                '25.00',
                '40.00',
                '0.0085',
                '0.038',
            ],
        ];
    }

    /**
     * Header kolom untuk Produk Jadi
     * @return array
     */
    public function headings(): array
    {
        return [
            'kode_barang',
            'nama_produk',
            'stok_awal',
            'nw_per_box',
            'gw_per_box',
            'wood_consumed_per_pcs',
            'm3_per_carton',
        ];
    }
}
