<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BomTemplateExport implements FromCollection, WithHeadings, WithStyles
{
    public function collection()
    {
        return new Collection([
            [
                'Kode Produk Utama' => 'PROD-001',
                'Kode Komponen'     => 'COMP-001',
                'Jumlah per Produk' => 2,
            ],
        ]);
    }

    public function headings(): array
    {
        return [
            'Kode Produk Utama',   // parent
            'Kode Komponen',       // child
            'Jumlah per Produk',   // qty_per_induk
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]], // Bold header
        ];
    }
}
