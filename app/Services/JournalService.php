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

            // ✅ CRITICAL FIX: Cleanup orphan lines before creating new journal
            $orphanDeleted = DB::delete("
                DELETE jl FROM journal_entry_lines jl
                LEFT JOIN journal_entries je ON jl.journal_entry_id = je.id
                WHERE je.id IS NULL
            ");

            if ($orphanDeleted > 0) {
                Log::warning("⚠️ Cleaned up {$orphanDeleted} orphan journal lines");
            }

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

            // ✅ Log history
            $this->logHistory(
                $journal->id,
                \App\Models\JournalHistory::ACTION_CREATED,
                'System created journal'
            );

            return $journal->fresh(['lines.account']);
        });
    }

    /**
     * ✅ UPDATED: Simple logic dengan 1 COA
     */
    public function createFromPurchaseBill(PurchaseBill $bill): JournalEntry
    {
        return DB::transaction(function () use ($bill) {

            // ✅ CRITICAL FIX: Cleanup orphan lines before creating new journal
            $orphanDeleted = DB::delete("
                DELETE jl FROM journal_entry_lines jl
                LEFT JOIN journal_entries je ON jl.journal_entry_id = je.id
                WHERE je.id IS NULL
            ");

            if ($orphanDeleted > 0) {
                Log::warning("⚠️ Cleaned up {$orphanDeleted} orphan journal lines");
            }

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

            // ✅ Log history
            $this->logHistory(
                $journal->id,
                \App\Models\JournalHistory::ACTION_CREATED,
                "Created from Purchase Bill: {$bill->bill_number}"
            );

            Log::info('=== JURNAL SELESAI ===');

            return $journal->fresh(['lines.account']);
        });
    }

    /**
     * Get atau create akun PPN Masukan
     */
    private function getPPNMasukanAccount(): ?ChartOfAccount
    {
        $ppnAccount = ChartOfAccount::where(function ($query) {
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
                ->where(function ($query) {
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

    /**
     * ✅ LOG HISTORY: Simpan audit trail
     */
    public function logHistory(
        int $journalEntryId,
        string $action,
        ?string $reason = null,
        ?array $oldData = null,
        ?array $newData = null
    ): void {
        \App\Models\JournalHistory::log($journalEntryId, $action, $reason, $oldData, $newData);
    }

    /**
     * ✅ UNPOST JURNAL: POSTED → DRAFT
     */
    public function unpostJournal(JournalEntry $journal, string $reason): JournalEntry
    {
        DB::beginTransaction();
        try {
            // Validasi
            if (!$journal->canUnpost()) {
                throw new \Exception('Jurnal ini tidak bisa di-unpost.');
            }

            // Simpan data lama untuk history
            $oldData = [
                'status' => $journal->status,
            ];

            // Update jurnal
            $journal->update([
                'status' => JournalEntry::STATUS_DRAFT,
                'unposted_by' => auth()->id(),
                'unposted_at' => now(),
                'unpost_reason' => $reason,
            ]);

            // Log history
            $this->logHistory(
                $journal->id,
                \App\Models\JournalHistory::ACTION_UNPOSTED,
                $reason,
                $oldData,
                ['status' => JournalEntry::STATUS_DRAFT]
            );

            DB::commit();

            Log::info('✅ Jurnal berhasil di-unpost', [
                'journal_number' => $journal->journal_number,
                'reason' => $reason,
            ]);

            return $journal->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Error unpost jurnal: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ✅ REPOST JURNAL: DRAFT → POSTED
     */
    public function repostJournal(JournalEntry $journal): JournalEntry
    {
        DB::beginTransaction();
        try {
            // Validasi
            if (!$journal->isDraft()) {
                throw new \Exception('Hanya jurnal DRAFT yang bisa di-post.');
            }

            if (!$journal->isBalanced()) {
                throw new \Exception('Jurnal tidak balance! Tidak bisa di-post.');
            }

            // Update status
            $journal->update([
                'status' => JournalEntry::STATUS_POSTED,
            ]);

            // Log history
            $this->logHistory(
                $journal->id,
                \App\Models\JournalHistory::ACTION_POSTED,
                'Reposted after edit'
            );

            DB::commit();

            Log::info('✅ Jurnal berhasil di-post ulang', [
                'journal_number' => $journal->journal_number,
            ]);

            return $journal->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Error repost jurnal: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ✅ VOID JURNAL
     */
    public function voidJournal(JournalEntry $journal, string $reason): void
    {
        DB::beginTransaction();
        try {
            if (!$journal->canVoid()) {
                throw new \Exception('Jurnal ini tidak bisa di-void.');
            }

            $journal->update(['status' => JournalEntry::STATUS_VOID]);

            // Log history
            $this->logHistory(
                $journal->id,
                \App\Models\JournalHistory::ACTION_VOIDED,
                $reason
            );

            DB::commit();

            Log::info('✅ Jurnal berhasil di-void', [
                'journal_number' => $journal->journal_number,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * ✅ UPDATE JURNAL MANUAL (untuk yang DRAFT)
     */
    public function updateManualJournal(
        JournalEntry $journal,
        string $date,
        string $description,
        array $entries
    ): JournalEntry {
        DB::beginTransaction();
        try {
            // Validasi
            if (!$journal->canEdit()) {
                throw new \Exception('Hanya jurnal DRAFT yang bisa di-edit.');
            }

            // Simpan data lama untuk history
            $oldData = [
                'date' => $journal->date,
                'description' => $journal->description,
                'entries_count' => $journal->lines->count(),
            ];

            // Hapus lines lama
            $journal->lines()->delete();

            // Buat lines baru & hitung total
            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($entries as $entry) {
                \App\Models\JournalEntryLine::create([
                    'journal_entry_id' => $journal->id,
                    'account_id' => $entry['account_id'],
                    'description' => $entry['description'] ?? '',
                    'debit' => $entry['debit'] ?? 0,
                    'credit' => $entry['credit'] ?? 0,
                ]);

                $totalDebit += $entry['debit'] ?? 0;
                $totalCredit += $entry['credit'] ?? 0;
            }

            // Update jurnal
            $journal->update([
                'date' => $date,
                'description' => $description,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'last_edited_by' => auth()->id(),
                'last_edited_at' => now(),
            ]);

            // Validasi balance
            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                throw new \Exception(
                    "Jurnal tidak balance! Debit: Rp " . number_format($totalDebit, 2, ',', '.') .
                    ", Kredit: Rp " . number_format($totalCredit, 2, ',', '.')
                );
            }

            // Log history
            $this->logHistory(
                $journal->id,
                \App\Models\JournalHistory::ACTION_EDITED,
                null,
                $oldData,
                [
                    'date' => $date,
                    'description' => $description,
                    'entries_count' => count($entries),
                ]
            );

            DB::commit();

            Log::info('✅ Jurnal manual berhasil di-update', [
                'journal_number' => $journal->journal_number,
            ]);

            return $journal->fresh(['lines.account']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Error update jurnal manual: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ✅ REVERSE JURNAL: Buat jurnal balik
     */
    public function reverseJournal(JournalEntry $journal): void
    {
        DB::beginTransaction();
        try {
            $journal->update(['status' => JournalEntry::STATUS_VOID]);

            // Log history
            $this->logHistory(
                $journal->id,
                \App\Models\JournalHistory::ACTION_VOIDED,
                'Journal reversed'
            );

            DB::commit();

            Log::info('✅ Jurnal berhasil di-reverse (VOID)', [
                'journal_number' => $journal->journal_number,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}