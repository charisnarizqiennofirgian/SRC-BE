<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ChartOfAccountTemplateExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    public function array(): array
    {
        return [
            ['110.01.000', 'Kas Besar', 'ASET', 'IDR'],
            ['110.02.000', 'Bank BCA', 'ASET', 'IDR'],
            ['210.01.000', 'Hutang Dagang', 'KEWAJIBAN', 'IDR'],
            ['310.01.000', 'Modal Disetor', 'MODAL', 'IDR'],
            ['410.01.000', 'Penjualan', 'PENDAPATAN', 'IDR'],
            ['510.01.000', 'Biaya Gaji', 'BIAYA', 'IDR'],
        ];
    }

    public function headings(): array
    {
        return [
            'CODE',
            'NAME',
            'TYPE',
            'CURRENCY',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
