<?php

namespace App\Exports;

use App\Models\ChartOfAccount;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ChartOfAccountExport implements FromCollection, WithHeadings, WithMapping
{
    public function collection()
    {
        return ChartOfAccount::orderBy('code', 'asc')->get();
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

    public function map($account): array
    {
        return [
            $account->code,
            $account->name,
            $account->type,
            $account->currency,
        ];
    }
}
