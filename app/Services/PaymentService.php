<?php

namespace App\Services;

use App\Models\PurchasePayment;
use App\Models\PurchaseBill;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    /**
     * Proses pembayaran hutang supplier
     */
    public function processPayment(array $data): PurchasePayment
    {
        return DB::transaction(function () use ($data) {

            Log::info('=== MULAI PROSES PEMBAYARAN ===');

            // 1. Load Purchase Bill
            $bill = PurchaseBill::with(['supplier', 'paymentMethod'])
                                ->findOrFail($data['purchase_bill_id']);

            Log::info('Purchase Bill:', [
                'bill_number' => $bill->bill_number,
                'total' => $bill->total_amount,
                'paid' => $bill->paid_amount,
                'remaining' => $bill->remaining_amount,
            ]);

            // 2. Validasi: Cek apakah nominal bayar tidak melebihi sisa hutang
            if ($data['amount'] > $bill->remaining_amount) {
                throw new \Exception(
                    "Nominal pembayaran (Rp " . number_format($data['amount'], 0, ',', '.') .
                    ") melebihi sisa hutang (Rp " . number_format($bill->remaining_amount, 0, ',', '.') . ")"
                );
            }

            // 3. Simpan riwayat pembayaran
            $payment = PurchasePayment::create([
                'purchase_bill_id' => $data['purchase_bill_id'],
                'payment_number' => PurchasePayment::generatePaymentNumber(),
                'payment_date' => $data['payment_date'],
                'amount' => $data['amount'],
                'payment_method_id' => $data['payment_method_id'],
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            Log::info('Payment Created:', [
                'payment_number' => $payment->payment_number,
                'amount' => $payment->amount,
            ]);

            // 4. Update status di Purchase Bill
            $newPaidAmount = $bill->paid_amount + $data['amount'];
            $newRemainingAmount = $bill->total_amount - $newPaidAmount;

            // Tentukan status
            if ($newRemainingAmount <= 0) {
                $paymentStatus = 'PAID'; // Lunas
            } elseif ($newPaidAmount > 0) {
                $paymentStatus = 'PARTIAL'; // Sebagian
            } else {
                $paymentStatus = 'UNPAID'; // Belum bayar
            }

            $bill->update([
                'paid_amount' => $newPaidAmount,
                'remaining_amount' => max(0, $newRemainingAmount),
                'payment_status' => $paymentStatus,
            ]);

            Log::info('Purchase Bill Updated:', [
                'new_paid_amount' => $newPaidAmount,
                'new_remaining' => $newRemainingAmount,
                'status' => $paymentStatus,
            ]);

            // 5. Buat Jurnal Otomatis
            $journal = $this->createPaymentJournal($payment, $bill);

            // 6. Link jurnal ke payment
            $payment->update(['journal_entry_id' => $journal->id]);

            Log::info('=== PEMBAYARAN SELESAI ===');

            return $payment->fresh(['purchaseBill', 'paymentMethod', 'journalEntry']);
        });
    }

    /**
     * Buat jurnal pembayaran hutang
     */
    private function createPaymentJournal(PurchasePayment $payment, PurchaseBill $bill): JournalEntry
    {
        Log::info('=== MULAI BUAT JURNAL PEMBAYARAN ===');

        // 1. Buat Header Jurnal
        $journal = JournalEntry::create([
            'journal_number' => JournalEntry::generateJournalNumber(),
            'date' => $payment->payment_date,
            'description' => "Pembayaran hutang kepada {$bill->supplier->name} - {$payment->payment_number}",
            'reference_type' => 'PurchasePayment',
            'reference_id' => $payment->id,
            'total_debit' => $payment->amount,
            'total_credit' => $payment->amount,
            'status' => JournalEntry::STATUS_POSTED,
            'created_by' => Auth::id(),
        ]);

        // 2. DEBIT: Hutang Usaha (Hutang Berkurang)
        $hutangAccountId = $bill->supplier->payable_account_id;

        if (!$hutangAccountId) {
            // Cari akun hutang default
            $hutangAccount = ChartOfAccount::where('type', 'KEWAJIBAN')
                                          ->where('name', 'LIKE', '%Hutang%')
                                          ->where('is_active', true)
                                          ->first();
            $hutangAccountId = $hutangAccount?->id;
        }

        if (!$hutangAccountId) {
            throw new \Exception('Akun Hutang Usaha tidak ditemukan!');
        }

        JournalEntryLine::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $hutangAccountId,
            'description' => "Pembayaran hutang - {$bill->bill_number}",
            'debit' => $payment->amount,
            'credit' => 0,
        ]);

        Log::info('Debit Line Created:', [
            'account' => 'Hutang Usaha',
            'debit' => $payment->amount,
        ]);

        // 3. KREDIT: Bank/Kas (Uang Keluar)
        $kasAccountId = $payment->paymentMethod->account_id;

        if (!$kasAccountId) {
            throw new \Exception('Payment Method tidak memiliki akun COA!');
        }

        JournalEntryLine::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $kasAccountId,
            'description' => "Pembayaran via {$payment->paymentMethod->name}",
            'debit' => 0,
            'credit' => $payment->amount,
        ]);

        Log::info('Credit Line Created:', [
            'account' => $payment->paymentMethod->name,
            'credit' => $payment->amount,
        ]);

        // 4. Validasi Balance
        if ($journal->total_debit != $journal->total_credit) {
            throw new \Exception('Jurnal pembayaran tidak balance!');
        }

        Log::info('✅ Jurnal Pembayaran BALANCE!');

        return $journal->fresh(['lines.account']);
    }
}
