<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\WithTitle;

class ProdukJadiTemplateExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles, WithTitle
{
    /**
     * Data contoh untuk template Produk Jadi
     * @return array
     */
    public function array(): array
    {
        return [
            [
                'PJ-001',          // kode_barang
                'KILT DINING',     // nama_produk
                'Produk Jadi',     // kategori (nama kategori di master)
                'PCS',             // satuan (nama satuan di master)
                'SANWIL',          // gudang (kode gudang)
                10,                // stok_awal
                '4407.99',         // hs_code
                27.00,             // nw_per_box
                42.00,             // gw_per_box
                0.0099,            // wood_consumed_per_pcs
                0.045,             // m3_per_carton
            ],
            [
                'PJ-002',
                'CANAL SERIES',
                'Produk Jadi',
                'PCS',
                'SANWIL',
                5,
                '4407.99',
                25.00,
                40.00,
                0.0085,
                0.038,
            ],
        ];
    }

    /**
     * Header kolom untuk Produk Jadi
     * URUTAN HARUS SAMA DENGAN IMPORT
     * @return array
     */
    public function headings(): array
    {
        return [
            'kode_barang',
            'nama_produk',
            'kategori',                 // di-mapping ke master Category
            'satuan',                   // di-mapping ke master Unit
            'gudang',                   // kode warehouse
            'stok_awal',
            'hs_code',
            'nw_per_box',
            'gw_per_box',
            'wood_consumed_per_pcs',
            'm3_per_carton',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['argb' => 'FFFFFFFF'],
                ],
                'fill' => [
                    'fillType'  => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor'=> ['argb' => 'FF4472C4'],
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Template Produk Jadi';
    }
}
