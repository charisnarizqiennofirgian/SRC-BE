<?php

namespace App\Services;

use App\Models\JournalEntryLine;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

class GeneralLedgerService
{
    public function getGeneralLedger(
        int $accountId,
        string $startDate,
        string $endDate
    ): array {
        $account = ChartOfAccount::findOrFail($accountId);

        $saldoAwal = $this->calculateSaldoAwal($accountId, $startDate);

        $transactions = $this->getTransactions($accountId, $startDate, $endDate);

        $runningBalance = $saldoAwal;
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($transactions as $transaction) {
            $runningBalance += $transaction->debit - $transaction->credit;
            $transaction->running_balance = $runningBalance;

            $totalDebit += $transaction->debit;
            $totalCredit += $transaction->credit;
        }

        return [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ],
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'saldo_awal' => $saldoAwal,
            'transactions' => $transactions,
            'summary' => [
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'saldo_akhir' => $runningBalance,
            ],
        ];
    }

    private function calculateSaldoAwal(int $accountId, string $beforeDate): float
    {
        $saldo = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_lines.account_id', $accountId)
            ->where('journal_entries.date', '<', $beforeDate)
            ->where('journal_entries.status', 'POSTED')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) - COALESCE(SUM(journal_entry_lines.credit), 0) as saldo')
            ->value('saldo');

        return floatval($saldo ?? 0);
    }

    private function getTransactions(int $accountId, string $startDate, string $endDate)
    {
        return JournalEntryLine::with(['journalEntry:id,journal_number,date,description,reference_type'])
            ->whereHas('journalEntry', function($q) use ($startDate, $endDate) {
                $q->whereBetween('date', [$startDate, $endDate])
                  ->where('status', 'POSTED');
            })
            ->where('account_id', $accountId)
            ->select([
                'journal_entry_lines.*',
                DB::raw('(SELECT date FROM journal_entries WHERE id = journal_entry_lines.journal_entry_id) as transaction_date'),
                DB::raw('(SELECT journal_number FROM journal_entries WHERE id = journal_entry_lines.journal_entry_id) as journal_number')
            ])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('journal_entry_id', 'asc')
            ->get();
    }
}
