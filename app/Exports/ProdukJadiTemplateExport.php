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
                'PJ-001',           // kode_barang
                'KILT DINING',      // nama_produk
                10,                 // stok_awal (ANGKA, bukan string)
                '4407.99',          // hs_code
                27.00,              // nw_per_box (ANGKA)
                42.00,              // gw_per_box (ANGKA)
                0.0099,             // wood_consumed_per_pcs (ANGKA)
                0.045,              // m3_per_carton (ANGKA)
            ],
            [
                'PJ-002',           // kode_barang
                'CANAL SERIES',     // nama_produk
                5,                  // stok_awal (ANGKA)
                '4407.99',          // hs_code
                25.00,              // nw_per_box (ANGKA)
                40.00,              // gw_per_box (ANGKA)
                0.0085,             // wood_consumed_per_pcs (ANGKA)
                0.038,              // m3_per_carton (ANGKA)
            ],
        ];
    }

    /**
     * Header kolom untuk Produk Jadi
     * ✅ URUTAN HARUS SAMA DENGAN YANG DIBACA DI IMPORT
     * @return array
     */
    public function headings(): array
    {
        return [
            'kode_barang',
            'nama_produk',
            'stok_awal',
            'hs_code',
            'nw_per_box',
            'gw_per_box',
            'wood_consumed_per_pcs',
            'm3_per_carton',
        ];
    }

    /**
     * ✅ STYLING UNTUK HEADER
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['argb' => 'FFFFFFFF'],
                ],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF4472C4'],
                ],
            ],
        ];
    }

    /**
     * ✅ NAMA SHEET
     */
    public function title(): string
    {
        return 'Template Produk Jadi';
    }
}
