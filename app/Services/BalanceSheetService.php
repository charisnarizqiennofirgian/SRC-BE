<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

class BalanceSheetService
{
    protected $incomeStatementService;

    public function __construct(IncomeStatementService $incomeStatementService)
    {
        $this->incomeStatementService = $incomeStatementService;
    }

    public function generate(string $asOfDate): array
    {
        $aset = $this->getAset($asOfDate);
        $kewajiban = $this->getKewajiban($asOfDate);
        $modal = $this->getModal($asOfDate);
        $labaRugi = $this->getLabaTahunBerjalan($asOfDate);

        $totalAset = $aset->sum('amount');
        $totalKewajiban = $kewajiban->sum('amount');
        $totalModal = $modal->sum('amount');
        $totalPasiva = $totalKewajiban + $totalModal + $labaRugi;

        $isBalanced = abs($totalAset - $totalPasiva) < 0.01;
        $selisih = $totalAset - $totalPasiva;

        return [
            'as_of_date' => $asOfDate,
            'aset' => [
                'accounts' => $aset,
                'total' => $totalAset,
            ],
            'kewajiban' => [
                'accounts' => $kewajiban,
                'total' => $totalKewajiban,
            ],
            'modal' => [
                'accounts' => $modal,
                'total' => $totalModal,
            ],
            'laba_tahun_berjalan' => $labaRugi,
            'total_pasiva' => $totalPasiva,
            'is_balanced' => $isBalanced,
            'selisih' => $selisih,
        ];
    }

    private function getAset(string $asOfDate)
    {
        return $this->getAccountsByType('ASET', $asOfDate, 'debit');
    }

    private function getKewajiban(string $asOfDate)
    {
        return $this->getAccountsByType('KEWAJIBAN', $asOfDate, 'credit');
    }

    private function getModal(string $asOfDate)
    {
        return $this->getAccountsByType('MODAL', $asOfDate, 'credit');
    }

    private function getLabaTahunBerjalan(string $asOfDate): float
    {
        $year = date('Y', strtotime($asOfDate));
        $startDate = $year . '-01-01';

        $labaRugi = $this->incomeStatementService->generate($startDate, $asOfDate);

        return $labaRugi['laba_bersih'];
    }

    private function getAccountsByType(string $type, string $asOfDate, string $normalPosition)
    {
        $accounts = ChartOfAccount::where('type', $type)
            ->where('is_active', true)
            ->get();

        $result = [];

        foreach ($accounts as $account) {
            $debit = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_entry_lines.account_id', $account->id)
                ->where('journal_entries.date', '<=', $asOfDate)
                ->where('journal_entries.status', 'POSTED')
                ->sum('journal_entry_lines.debit');

            $credit = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_entry_lines.account_id', $account->id)
                ->where('journal_entries.date', '<=', $asOfDate)
                ->where('journal_entries.status', 'POSTED')
                ->sum('journal_entry_lines.credit');

            $amount = $normalPosition === 'debit'
                ? $debit - $credit
                : $credit - $debit;

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
