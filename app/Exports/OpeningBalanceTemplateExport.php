<?php

namespace App\Exports;

use App\Models\ChartOfAccount;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OpeningBalanceTemplateExport implements FromArray, WithHeadings, WithTitle, WithStyles
{
    public function array(): array
    {
        return ChartOfAccount::orderBy('code')
            ->get()
            ->map(fn($a) => [
                $a->code,
                $a->name,
                0, // SALDO AKHIR DEBIT — isi jika akun ini bersaldo debit
                0, // SALDO AKHIR KREDIT — isi jika akun ini bersaldo kredit
            ])
            ->toArray();
    }

    public function headings(): array
    {
        return ['KODE AKUN', 'NAMA AKUN', 'SALDO AKHIR DEBIT', 'SALDO AKHIR KREDIT'];
    }

    public function title(): string
    {
        return 'Opening Balance';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
