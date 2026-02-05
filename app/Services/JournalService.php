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

    /**
     * ✅ UPDATED: Simple logic dengan 1 COA
     */
    public function createFromPurchaseBill(PurchaseBill $bill): JournalEntry
    {
        return DB::transaction(function () use ($bill) {

            Log::info('=== MULAI BUAT JURNAL PEMBELIAN ===');
            Log::info('Purchase Bill:', [
                'bill_number' => $bill->bill_number,
                'subtotal' => $bill->subtotal,
                'ppn_amount' => $bill->ppn_amount,
                'total_amount' => $bill->total_amount,
                'payment_type' => $bill->payment_type,
                'coa_id' => $bill->coa_id,
                'paid_amount' => $bill->paid_amount,
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

            // ✅ DEBIT 1: Persediaan/Beban (Subtotal)
            if ($bill->subtotal > 0) {
                JournalEntryLine::create([
                    'journal_entry_id' => $journal->id,
                    'account_id' => $bill->coa_id,
                    'description' => "Pembelian barang dari {$bill->supplier->name}",
                    'debit' => $bill->subtotal,
                    'credit' => 0,
                ]);
                $totalDebit += $bill->subtotal;
                Log::info('Debit Persediaan/Beban:', ['amount' => $bill->subtotal]);
            }

            // ✅ DEBIT 2: PPN Masukan (jika ada)
            if ($bill->ppn_amount > 0) {
                $ppnAccount = $this->getPPNMasukanAccount();

                if ($ppnAccount) {
                    JournalEntryLine::create([
                        'journal_entry_id' => $journal->id,
                        'account_id' => $ppnAccount->id,
                        'description' => 'PPN Masukan ' . ($bill->ppn_percentage ?? 12) . '%',
                        'debit' => $bill->ppn_amount,
                        'credit' => 0,
                    ]);
                    $totalDebit += $bill->ppn_amount;
                    Log::info('Debit PPN Masukan:', ['amount' => $bill->ppn_amount]);
                }
            }

            // ✅ CREDIT: Berdasarkan Payment Type
            switch ($bill->payment_type) {
                case PurchaseBill::PAYMENT_TEMPO:
                    // Credit: Hutang Usaha (full)
                    $this->createHutangLine($journal, $bill, $bill->total_amount);
                    $totalCredit += $bill->total_amount;
                    Log::info('Credit Hutang Usaha (TEMPO):', ['amount' => $bill->total_amount]);
                    break;

                case PurchaseBill::PAYMENT_TUNAI:
                    // Credit: Kas/Bank (full) - pakai coa_id yang sama
                    JournalEntryLine::create([
                        'journal_entry_id' => $journal->id,
                        'account_id' => $bill->coa_id,
                        'description' => "Pembayaran tunai ke {$bill->supplier->name}",
                        'debit' => 0,
                        'credit' => $bill->total_amount,
                    ]);
                    $totalCredit += $bill->total_amount;
                    Log::info('Credit Kas/Bank (TUNAI):', ['amount' => $bill->total_amount]);
                    break;

                case PurchaseBill::PAYMENT_DP:
                    // Credit 1: Kas/Bank (DP) - pakai coa_id yang sama
                    if ($bill->paid_amount > 0) {
                        JournalEntryLine::create([
                            'journal_entry_id' => $journal->id,
                            'account_id' => $bill->coa_id,
                            'description' => "Pembayaran DP ke {$bill->supplier->name}",
                            'debit' => 0,
                            'credit' => $bill->paid_amount,
                        ]);
                        $totalCredit += $bill->paid_amount;
                        Log::info('Credit Kas/Bank (DP):', ['amount' => $bill->paid_amount]);
                    }

                    // Credit 2: Hutang Usaha (sisa)
                    if ($bill->remaining_amount > 0) {
                        $this->createHutangLine($journal, $bill, $bill->remaining_amount);
                        $totalCredit += $bill->remaining_amount;
                        Log::info('Credit Hutang Usaha (Sisa):', ['amount' => $bill->remaining_amount]);
                    }
                    break;
            }

            // Update totals
            $journal->update([
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ]);

            Log::info('=== VALIDASI BALANCE ===');
            Log::info('Totals:', [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'difference' => abs($totalDebit - $totalCredit),
            ]);

            // Validasi balance
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

    /**
     * Get atau create akun PPN Masukan
     */
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

                Log::info('✅ Akun PPN Masukan berhasil dibuat', [
                    'account_id' => $ppnAccount->id,
                ]);
            } catch (\Exception $e) {
                Log::error('❌ Gagal membuat akun PPN Masukan: ' . $e->getMessage());
                return null;
            }
        }

        return $ppnAccount;
    }

    /**
     * Create Credit line untuk Hutang Usaha
     */
    private function createHutangLine(JournalEntry $journal, PurchaseBill $bill, float $amount): void
    {
        // Prioritas: payable_account_id dari supplier
        $hutangAccountId = $bill->supplier->payable_account_id;

        // Fallback: cari akun Hutang Usaha
        if (!$hutangAccountId) {
            $hutangAccount = ChartOfAccount::where('type', 'KEWAJIBAN')
                ->where(function($query) {
                    $query->where('name', 'LIKE', '%Hutang Usaha%')
                          ->orWhere('code', 'LIKE', '2-2%');
                })
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
        } else {
            Log::error('❌ Akun Hutang Usaha tidak ditemukan!');
            throw new \Exception('Akun Hutang Usaha tidak ditemukan. Harap buat akun terlebih dahulu.');
        }
    }

    public function voidJournal(JournalEntry $journal): void
    {
        $journal->update(['status' => JournalEntry::STATUS_VOID]);
    }
}
