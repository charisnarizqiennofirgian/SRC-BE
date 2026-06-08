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
        $aset      = $this->getAset($asOfDate);
        $kewajiban = $this->getKewajiban($asOfDate);
        $modal     = $this->getModal($asOfDate);
        $labaRugi  = $this->getLabaTahunBerjalan($asOfDate);

        $totalAset      = $aset['total'];
        $totalKewajiban = $kewajiban['total'];
        $totalModal     = $modal['total'];
        $totalPasiva    = $totalKewajiban + $totalModal + $labaRugi;

        $isBalanced = abs($totalAset - $totalPasiva) < 1;
        $selisih    = $totalAset - $totalPasiva;

        return [
            'as_of_date'          => $asOfDate,
            'aset'                => $aset,
            'kewajiban'           => $kewajiban,
            'modal'               => $modal,
            'laba_tahun_berjalan' => $labaRugi,
            'total_pasiva'        => $totalPasiva,
            'is_balanced'         => $isBalanced,
            'selisih'             => $selisih,
        ];
    }

    private function getAset(string $asOfDate): array
    {
        $accounts = $this->fetchAccountAmounts('ASET', $asOfDate, 'debit');

        $lancar  = $accounts->where('sub_type', ChartOfAccount::SUB_TYPE_AKTIVA_LANCAR)->values();
        $tetap   = $accounts->where('sub_type', ChartOfAccount::SUB_TYPE_AKTIVA_TETAP)->values();
        $lainnya = $accounts->whereNotIn('sub_type', [
            ChartOfAccount::SUB_TYPE_AKTIVA_LANCAR,
            ChartOfAccount::SUB_TYPE_AKTIVA_TETAP,
        ])->values();

        return [
            'aktiva_lancar' => [
                'accounts' => $lancar,
                'total'    => $lancar->sum('amount'),
            ],
            'aktiva_tetap' => [
                'accounts' => $tetap,
                'total'    => $tetap->sum('amount'),
            ],
            'lainnya' => [
                'accounts' => $lainnya,
                'total'    => $lainnya->sum('amount'),
            ],
            'accounts' => $accounts->values(),
            'total'    => $accounts->sum('amount'),
        ];
    }

    private function getKewajiban(string $asOfDate): array
    {
        $accounts = $this->fetchAccountAmounts('KEWAJIBAN', $asOfDate, 'credit');

        $lancar        = $accounts->where('sub_type', ChartOfAccount::SUB_TYPE_HUTANG_LANCAR)->values();
        $jangkaPanjang = $accounts->where('sub_type', ChartOfAccount::SUB_TYPE_HUTANG_JANGKA_PANJANG)->values();
        $lainnya       = $accounts->whereNotIn('sub_type', [
            ChartOfAccount::SUB_TYPE_HUTANG_LANCAR,
            ChartOfAccount::SUB_TYPE_HUTANG_JANGKA_PANJANG,
        ])->values();

        return [
            'hutang_lancar' => [
                'accounts' => $lancar,
                'total'    => $lancar->sum('amount'),
            ],
            'hutang_jangka_panjang' => [
                'accounts' => $jangkaPanjang,
                'total'    => $jangkaPanjang->sum('amount'),
            ],
            'lainnya' => [
                'accounts' => $lainnya,
                'total'    => $lainnya->sum('amount'),
            ],
            'accounts' => $accounts->values(),
            'total'    => $accounts->sum('amount'),
        ];
    }

    private function getModal(string $asOfDate): array
    {
        $accounts = $this->fetchAccountAmounts('MODAL', $asOfDate, 'credit');

        return [
            'accounts' => $accounts->values(),
            'total'    => $accounts->sum('amount'),
        ];
    }

    private function getLabaTahunBerjalan(string $asOfDate): float
    {
        $year      = date('Y', strtotime($asOfDate));
        $startDate = $year . '-01-01';
        $labaRugi  = $this->incomeStatementService->generate($startDate, $asOfDate);

        return $labaRugi['laba_bersih'];
    }

    private function fetchAccountAmounts(string $type, string $asOfDate, string $normalPosition)
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
                    'account_id'   => $account->id,
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'sub_type'     => $account->sub_type,
                    'debit'        => $debit,
                    'credit'       => $credit,
                    'amount'       => $amount,
                ];
            }
        }

        return collect($result);
    }
}
