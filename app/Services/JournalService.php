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
    /**
     * Buat jurnal otomatis dari Purchase Bill
     */
    public function createFromPurchaseBill(PurchaseBill $bill): JournalEntry
    {
        return DB::transaction(function () use ($bill) {

            // 🔍 DEBUG: Log data purchase bill
            Log::info('=== MULAI BUAT JURNAL ===');
            Log::info('Purchase Bill Data:', [
                'bill_number' => $bill->bill_number,
                'subtotal' => $bill->subtotal,
                'ppn_amount' => $bill->ppn_amount,
                'ppn_percentage' => $bill->ppn_percentage ?? 'NULL',
                'total_amount' => $bill->total_amount,
                'payment_type' => $bill->payment_type,
            ]);

            // 1. Buat header jurnal
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

            // 2. DEBIT: Persediaan/Biaya per item (subtotal tanpa PPN)
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

            // 🆕 3. DEBIT: PPN Masukan (kalau ada PPN)
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

            // 4. KREDIT: Hutang atau Kas/Bank berdasarkan payment type
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

            // 5. Update total di header jurnal
            $journal->update([
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ]);

            // 🔥 6. VALIDASI: Pastikan jurnal balance!
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

            // 7. Link jurnal ke purchase bill
            $bill->update(['journal_entry_id' => $journal->id]);

            Log::info('=== JURNAL SELESAI ===');

            return $journal->fresh(['lines.account']);
        });
    }

    /**
     * 🆕 Ambil akun PPN Masukan
     */
    private function getPPNMasukanAccount(): ?ChartOfAccount
{
    // 1. Cari akun PPN Masukan yang sudah ada
    $ppnAccount = ChartOfAccount::where(function($query) {
            $query->where('code', '1107')
                  ->orWhere('name', 'LIKE', '%PPN Masukan%')
                  ->orWhere('name', 'LIKE', '%Pajak Masukan%');
        })
        ->where('is_active', true)
        ->first();

    // 2. Kalau belum ada, buat otomatis!
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
    /**
     * Buat line kredit untuk Hutang Dagang
     */
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

    /**
     * Buat line kredit untuk Kas/Bank
     */
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

    /**
     * Void/Batalkan jurnal
     */
    public function voidJournal(JournalEntry $journal): void
    {
        $journal->update(['status' => JournalEntry::STATUS_VOID]);
    }
}


