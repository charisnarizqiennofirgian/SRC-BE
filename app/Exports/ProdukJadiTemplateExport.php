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
    public function array(): array
    {
        return [
            [
                'PJ-001',
                'KILT DINING',
                'Produk Jadi',
                'PCS',
                'SANWIL',
                10,
                '4407.99',
                27.00,
                42.00,
                0.0099,
                0.045,
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

    public function headings(): array
    {
        return [
            'kode_barang',
            'nama_produk',
            'kategori',
            'satuan',
            'gudang',
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
