<?php

namespace App\Services;

use App\Models\InvoicePayment;
use App\Models\SalesInvoice;
use App\Models\DownPayment;
use Illuminate\Support\Facades\DB;

class InvoicePaymentService
{
    protected $journalService;
    protected $downPaymentService;
    protected $invoiceService;

    public function __construct(
        JournalService $journalService,
        DownPaymentService $downPaymentService,
        InvoiceService $invoiceService
    ) {
        $this->journalService = $journalService;
        $this->downPaymentService = $downPaymentService;
        $this->invoiceService = $invoiceService;
    }

    public function receiveCashPayment(
        int $salesInvoiceId,
        string $paymentDate,
        float $amount,
        int $accountId,
        ?string $notes = null,
        ?int $userId = null
    ): InvoicePayment {
        DB::beginTransaction();
        try {
            $invoice = SalesInvoice::findOrFail($salesInvoiceId);

            if ($invoice->payment_status === 'PAID') {
                throw new \Exception('Invoice sudah lunas');
            }

            if ($amount > $invoice->remaining_amount) {
                throw new \Exception('Jumlah pembayaran melebihi sisa tagihan');
            }

            $paymentNumber = InvoicePayment::generatePaymentNumber();

            $payment = InvoicePayment::create([
                'payment_number' => $paymentNumber,
                'sales_invoice_id' => $salesInvoiceId,
                'buyer_id' => $invoice->buyer_id,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'account_id' => $accountId,
                'payment_type' => 'CASH',
                'notes' => $notes,
                'created_by' => $userId ?? auth()->id(),
            ]);

            $this->createCashPaymentJournal($payment);

            $invoice->paid_amount += $amount;
            $this->invoiceService->updatePaymentStatus($invoice);

            DB::commit();
            return $payment->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function receiveDownPaymentDeduction(
        int $salesInvoiceId,
        int $downPaymentId,
        float $amount,
        ?string $notes = null,
        ?int $userId = null
    ): InvoicePayment {
        DB::beginTransaction();
        try {
            $invoice = SalesInvoice::findOrFail($salesInvoiceId);
            $downPayment = DownPayment::findOrFail($downPaymentId);

            if ($invoice->payment_status === 'PAID') {
                throw new \Exception('Invoice sudah lunas');
            }

            if ($amount > $invoice->remaining_amount) {
                throw new \Exception('Jumlah pembayaran melebihi sisa tagihan');
            }

            if (!$downPayment->isAvailable()) {
                throw new \Exception('Down Payment tidak tersedia');
            }

            if ($amount > $downPayment->remaining_amount) {
                throw new \Exception('Jumlah melebihi sisa DP');
            }

            $paymentNumber = InvoicePayment::generatePaymentNumber();

            $payment = InvoicePayment::create([
                'payment_number' => $paymentNumber,
                'sales_invoice_id' => $salesInvoiceId,
                'buyer_id' => $invoice->buyer_id,
                'payment_date' => now(),
                'amount' => $amount,
                'account_id' => $downPayment->account_id,
                'payment_type' => 'DP',
                'down_payment_id' => $downPaymentId,
                'notes' => $notes,
                'created_by' => $userId ?? auth()->id(),
            ]);

            $this->createDpDeductionJournal($payment, $downPayment);

            $this->downPaymentService->useDownPayment($downPayment, $amount);

            $invoice->paid_amount += $amount;
            $this->invoiceService->updatePaymentStatus($invoice);

            DB::commit();
            return $payment->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function createCashPaymentJournal(InvoicePayment $payment): void
    {
        $invoice = $payment->salesInvoice;
        $buyer = $invoice->buyer;

        $cashAccount = $payment->account;
        if (!$cashAccount) {
            throw new \Exception('Akun kas/bank tidak ditemukan');
        }

        if (!$buyer->receivable_account_id) {
            throw new \Exception('Buyer belum memiliki akun piutang');
        }

        $entries = [
            [
                'account_id' => $cashAccount->id,
                'debit' => $payment->amount,
                'credit' => 0,
                'description' => "Terima Pembayaran - {$payment->payment_number}",
            ],
            [
                'account_id' => $buyer->receivable_account_id,
                'debit' => 0,
                'credit' => $payment->amount,
                'description' => "Pelunasan Piutang - {$invoice->invoice_number}",
            ],
        ];

        $journalEntry = $this->journalService->createJournal(
            date: $payment->payment_date,
            description: "Pembayaran Invoice - {$invoice->invoice_number}",
            entries: $entries,
            referenceType: 'invoice_payment',
            referenceId: $payment->id
        );

        $payment->update(['journal_entry_id' => $journalEntry->id]);
    }

    protected function createDpDeductionJournal(InvoicePayment $payment, DownPayment $downPayment): void
    {
        $invoice = $payment->salesInvoice;
        $buyer = $invoice->buyer;

        if (!$buyer->receivable_account_id) {
            throw new \Exception('Buyer belum memiliki akun piutang');
        }

        $depositAccount = \App\Models\ChartOfAccount::where('code', 'like', '2-1%')
            ->where(function($q) {
                $q->where('name', 'like', '%Uang Muka Penjualan%')
                  ->orWhere('name', 'like', '%Customer Deposit%');
            })
            ->first();

        if (!$depositAccount) {
            throw new \Exception('Akun Uang Muka Penjualan tidak ditemukan');
        }

        $entries = [
            [
                'account_id' => $depositAccount->id,
                'debit' => $payment->amount,
                'credit' => 0,
                'description' => "Pemotongan DP - {$downPayment->dp_number}",
            ],
            [
                'account_id' => $buyer->receivable_account_id,
                'debit' => 0,
                'credit' => $payment->amount,
                'description' => "Pelunasan dengan DP - {$invoice->invoice_number}",
            ],
        ];

        $journalEntry = $this->journalService->createJournal(
            date: $payment->payment_date,
            description: "Pemotongan DP ke Invoice - {$invoice->invoice_number}",
            entries: $entries,
            referenceType: 'invoice_payment',
            referenceId: $payment->id
        );

        $payment->update(['journal_entry_id' => $journalEntry->id]);
    }
}
