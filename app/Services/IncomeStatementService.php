<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

class IncomeStatementService
{
    public function generate(string $startDate, string $endDate): array
    {
        $pendapatan = $this->getPendapatan($startDate, $endDate);
        $hpp = $this->getHpp($startDate, $endDate);
        $biaya = $this->getBiaya($startDate, $endDate);

        $totalPendapatan = $pendapatan->sum('amount');
        $totalHpp = $hpp->sum('amount');
        $totalBiaya = $biaya->sum('amount');

        $labaKotor = $totalPendapatan - $totalHpp;
        $labaBersih = $labaKotor - $totalBiaya;

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'pendapatan' => [
                'accounts' => $pendapatan,
                'total' => $totalPendapatan,
            ],
            'hpp' => [
                'accounts' => $hpp,
                'total' => $totalHpp,
            ],
            'laba_kotor' => $labaKotor,
            'biaya' => [
                'accounts' => $biaya,
                'total' => $totalBiaya,
            ],
            'laba_bersih' => $labaBersih,
        ];
    }

    private function getPendapatan(string $startDate, string $endDate)
    {
        return $this->getAccountsByType('PENDAPATAN', $startDate, $endDate, 'credit');
    }

    private function getHpp(string $startDate, string $endDate)
    {
        return $this->getAccountsByType('HPP', $startDate, $endDate, 'debit');
    }

    private function getBiaya(string $startDate, string $endDate)
    {
        return $this->getAccountsByType('BIAYA', $startDate, $endDate, 'debit');
    }

    private function getAccountsByType(string $type, string $startDate, string $endDate, string $normalPosition)
    {
        $accounts = ChartOfAccount::where('type', $type)
            ->where('is_active', true)
            ->get();

        $result = [];

        foreach ($accounts as $account) {
            $debit = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_entry_lines.account_id', $account->id)
                ->whereBetween('journal_entries.date', [$startDate, $endDate])
                ->where('journal_entries.status', 'POSTED')
                ->sum('journal_entry_lines.debit');

            $credit = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_entry_lines.account_id', $account->id)
                ->whereBetween('journal_entries.date', [$startDate, $endDate])
                ->where('journal_entries.status', 'POSTED')
                ->sum('journal_entry_lines.credit');

            $amount = $normalPosition === 'credit'
                ? $credit - $debit
                : $debit - $credit;

            if ($amount != 0) {
                $result[] = [
                    'account_id' => $account->id,
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'debit' => $debit,
                    'credit' => $credit,
                    'amount' => $amount,
                ];
            }
        }

        return collect($result);
    }
}
