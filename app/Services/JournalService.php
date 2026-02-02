<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PurchaseBill;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class JournalService
{
    public function createJournal(
        string $date,
        string $description,
        array $entries,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): JournalEntry {
        return DB::transaction(function () use ($date, $description, $entries, $referenceType, $referenceId) {

            $journal = JournalEntry::create([
                'journal_number' => JournalEntry::generateJournalNumber(),
                'date' => $date,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'total_debit' => 0,
                'total_credit' => 0,
                'status' => JournalEntry::STATUS_POSTED,
                'created_by' => Auth::id(),
            ]);

            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($entries as $entry) {
                JournalEntryLine::create([
                    'journal_entry_id' => $journal->id,
                    'account_id' => $entry['account_id'],
                    'description' => $entry['description'],
                    'debit' => $entry['debit'] ?? 0,
                    'credit' => $entry['credit'] ?? 0,
                ]);

                $totalDebit += $entry['debit'] ?? 0;
                $totalCredit += $entry['credit'] ?? 0;
            }

            $journal->update([
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ]);

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                throw new \Exception(
                    "Jurnal tidak balance! Debit: Rp " . number_format($totalDebit, 2, ',', '.') .
                    ", Kredit: Rp " . number_format($totalCredit, 2, ',', '.')
                );
            }

            Log::info('✅ Jurnal berhasil dibuat', [
                'journal_number' => $journal->journal_number,
                'debit' => $totalDebit,
                'credit' => $totalCredit,
            ]);

            return $journal->fresh(['lines.account']);
        });
    }

    public function createFromPurchaseBill(PurchaseBill $bill): JournalEntry
    {
        return DB::transaction(function () use ($bill) {

            Log::info('=== MULAI BUAT JURNAL ===');
            Log::info('Purchase Bill Data:', [
                'bill_number' => $bill->bill_number,
                'subtotal' => $bill->subtotal,
                'ppn_amount' => $bill->ppn_amount,
                'ppn_percentage' => $bill->ppn_percentage ?? 'NULL',
                'total_amount' => $bill->total_amount,
                'payment_type' => $bill->payment_type,
            ]);

            $journal = JournalEntry::create([
                'journal_number' => JournalEntry::generateJournalNumber(),
                'date' => $bill->bill_date,
                'description' => "Pembelian dari {$bill->supplier->name} - {$bill->bill_number}",
                'reference_type' => 'PurchaseBill',
                'reference_id' => $bill->id,
                'total_debit' => 0,
                'total_credit' => 0,
                'status' => JournalEntry::STATUS_POSTED,
                'created_by' => Auth::id(),
            ]);

            $totalDebit = 0;
            $totalCredit = 0;

            Log::info('=== PROSES ITEMS ===');
            foreach ($bill->details as $detail) {
                if ($detail->account_id && $detail->subtotal > 0) {
                    Log::info('Item Detail:', [
                        'item_name' => $detail->item->name ?? 'N/A',
                        'quantity' => $detail->quantity,
                        'price' => $detail->price,
                        'subtotal' => $detail->subtotal,
                        'account_id' => $detail->account_id,
                    ]);

                    JournalEntryLine::create([
                        'journal_entry_id' => $journal->id,
                        'account_id' => $detail->account_id,
                        'description' => $detail->item->name ?? 'Item',
                        'debit' => $detail->subtotal,
                        'credit' => 0,
                    ]);
                    $totalDebit += $detail->subtotal;
                }
            }

            Log::info('Total Debit setelah items:', ['totalDebit' => $totalDebit]);

            Log::info('=== PROSES PPN ===');
            Log::info('PPN Amount:', ['ppn_amount' => $bill->ppn_amount]);

            if ($bill->ppn_amount > 0) {
                $ppnAccount = $this->getPPNMasukanAccount();

                Log::info('PPN Account:', [
                    'found' => $ppnAccount ? 'YES' : 'NO',
                    'account_id' => $ppnAccount->id ?? 'NULL',
                    'account_name' => $ppnAccount->name ?? 'NULL',
                ]);

                if ($ppnAccount) {
                    JournalEntryLine::create([
                        'journal_entry_id' => $journal->id,
                        'account_id' => $ppnAccount->id,
                        'description' => 'PPN Masukan ' . ($bill->ppn_percentage ?? 11) . '%',
                        'debit' => $bill->ppn_amount,
                        'credit' => 0,
                    ]);
                    $totalDebit += $bill->ppn_amount;

                    Log::info('PPN Line Created:', ['ppn_amount' => $bill->ppn_amount]);
                }
            } else {
                Log::warning('PPN Amount adalah 0 atau NULL!');
            }

            Log::info('Total Debit setelah PPN:', ['totalDebit' => $totalDebit]);

            Log::info('=== PROSES KREDIT ===');
            Log::info('Payment Type:', ['payment_type' => $bill->payment_type]);

            switch ($bill->payment_type) {
                case PurchaseBill::PAYMENT_TEMPO:
                    $this->createHutangLine($journal, $bill, $bill->total_amount);
                    $totalCredit += $bill->total_amount;
                    Log::info('Hutang Line Created:', ['amount' => $bill->total_amount]);
                    break;

                case PurchaseBill::PAYMENT_TUNAI:
                    $this->createKasLine($journal, $bill, $bill->total_amount);
                    $totalCredit += $bill->total_amount;
                    Log::info('Kas Line Created:', ['amount' => $bill->total_amount]);
                    break;

                case PurchaseBill::PAYMENT_DP:
                    if ($bill->paid_amount > 0) {
                        $this->createKasLine($journal, $bill, $bill->paid_amount);
                        $totalCredit += $bill->paid_amount;
                        Log::info('Kas DP Line Created:', ['amount' => $bill->paid_amount]);
                    }
                    if ($bill->remaining_amount > 0) {
                        $this->createHutangLine($journal, $bill, $bill->remaining_amount);
                        $totalCredit += $bill->remaining_amount;
                        Log::info('Hutang Sisa Line Created:', ['amount' => $bill->remaining_amount]);
                    }
                    break;
            }

            Log::info('Total Credit setelah payment:', ['totalCredit' => $totalCredit]);

            $journal->update([
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ]);

            Log::info('=== VALIDASI BALANCE ===');
            Log::info('Final Totals:', [
                'totalDebit' => $totalDebit,
                'totalCredit' => $totalCredit,
                'difference' => abs($totalDebit - $totalCredit),
            ]);

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                Log::error('JURNAL TIDAK BALANCE!', [
                    'debit' => $totalDebit,
                    'credit' => $totalCredit,
                ]);

                throw new \Exception(
                    "Jurnal tidak balance! Debit: Rp " . number_format($totalDebit, 2, ',', '.') .
                    ", Kredit: Rp " . number_format($totalCredit, 2, ',', '.')
                );
            }

            Log::info('✅ Jurnal BALANCE!');

            $bill->update(['journal_entry_id' => $journal->id]);

            Log::info('=== JURNAL SELESAI ===');

            return $journal->fresh(['lines.account']);
        });
    }

    private function getPPNMasukanAccount(): ?ChartOfAccount
    {
        $ppnAccount = ChartOfAccount::where(function($query) {
                $query->where('code', '1107')
                      ->orWhere('name', 'LIKE', '%PPN Masukan%')
                      ->orWhere('name', 'LIKE', '%Pajak Masukan%');
            })
            ->where('is_active', true)
            ->first();

        if (!$ppnAccount) {
            Log::warning('⚠️ Akun PPN Masukan tidak ditemukan, membuat otomatis...');

            try {
                $ppnAccount = ChartOfAccount::create([
                    'code' => '1107',
                    'name' => 'PPN Masukan',
                    'type' => 'ASET',
                    'normal_position' => 'debit',
                    'is_active' => true,
                ]);

                Log::info('✅ Akun PPN Masukan berhasil dibuat otomatis', [
                    'account_id' => $ppnAccount->id,
                    'code' => $ppnAccount->code,
                ]);
            } catch (\Exception $e) {
                Log::error('❌ Gagal membuat akun PPN Masukan otomatis: ' . $e->getMessage());
                return null;
            }
        }

        return $ppnAccount;
    }

    private function createHutangLine(JournalEntry $journal, PurchaseBill $bill, float $amount): void
    {
        $hutangAccountId = $bill->supplier->payable_account_id;

        if (!$hutangAccountId) {
            $hutangAccount = ChartOfAccount::where('type', 'KEWAJIBAN')
                ->where('is_active', true)
                ->first();
            $hutangAccountId = $hutangAccount?->id;
        }

        if ($hutangAccountId) {
            JournalEntryLine::create([
                'journal_entry_id' => $journal->id,
                'account_id' => $hutangAccountId,
                'description' => "Hutang ke {$bill->supplier->name}",
                'debit' => 0,
                'credit' => $amount,
            ]);
        }
    }

    private function createKasLine(JournalEntry $journal, PurchaseBill $bill, float $amount): void
    {
        $kasAccountId = $bill->paymentMethod?->account_id;

        if ($kasAccountId) {
            JournalEntryLine::create([
                'journal_entry_id' => $journal->id,
                'account_id' => $kasAccountId,
                'description' => "Pembayaran via {$bill->paymentMethod->name}",
                'debit' => 0,
                'credit' => $amount,
            ]);
        }
    }

    public function voidJournal(JournalEntry $journal): void
    {
        $journal->update(['status' => JournalEntry::STATUS_VOID]);
    }
}
