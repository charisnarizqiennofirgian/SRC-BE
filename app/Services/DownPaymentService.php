<?php

namespace App\Services;

use App\Models\DownPayment;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

class DownPaymentService
{
    protected $journalService;

    public function __construct(JournalService $journalService)
    {
        $this->journalService = $journalService;
    }

    public function receiveDownPayment(
        int $salesOrderId,
        string $paymentDate,
        float $amount,
        int $accountId,
        float $exchangeRate = 1,
        ?string $notes = null,
        ?int $userId = null
    ): DownPayment {
        $salesOrder = SalesOrder::with('buyer')->findOrFail($salesOrderId);
        $currency = $salesOrder->currency ?? 'IDR';

        $amountIdr = $amount * $exchangeRate;

        $dpNumber = DownPayment::generateDpNumber();

        $downPayment = DownPayment::create([
            'dp_number' => $dpNumber,
            'sales_order_id' => $salesOrderId,
            'buyer_id' => $salesOrder->buyer_id,
            'payment_date' => $paymentDate,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'amount_original' => $amount,
            'amount_idr' => $amountIdr,
            'account_id' => $accountId,
            'status' => 'PENDING',
            'used_amount' => 0,
            'remaining_amount' => $amountIdr,
            'notes' => $notes,
            'created_by' => $userId ?? auth()->id(),
        ]);

        $this->createDownPaymentJournal($downPayment);

        return $downPayment->fresh();
    }

    protected function createDownPaymentJournal(DownPayment $downPayment): void
    {
        $cashAccount = $downPayment->account;
        if (!$cashAccount) {
            throw new \Exception('Akun kas/bank tidak ditemukan');
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
                'account_id' => $cashAccount->id,
                'debit' => $downPayment->amount_idr,
                'credit' => 0,
                'description' => "Terima DP - {$downPayment->dp_number}",
            ],
            [
                'account_id' => $depositAccount->id,
                'debit' => 0,
                'credit' => $downPayment->amount_idr,
                'description' => "Uang Muka - {$downPayment->dp_number}",
            ],
        ];

        $journalEntry = $this->journalService->createJournal(
            date: $downPayment->payment_date,
            description: "Terima Uang Muka - {$downPayment->dp_number}",
            entries: $entries,
            referenceType: 'down_payment',
            referenceId: $downPayment->id
        );

        $downPayment->update(['journal_entry_id' => $journalEntry->id]);
    }

    public function getAvailableDownPayments(int $buyerId)
    {
        return DownPayment::where('buyer_id', $buyerId)
            ->where('status', 'PENDING')
            ->where('remaining_amount', '>', 0)
            ->orderBy('payment_date', 'asc')
            ->get();
    }

    public function useDownPayment(DownPayment $downPayment, float $amount): void
    {
        if (!$downPayment->isAvailable()) {
            throw new \Exception('Down Payment tidak tersedia');
        }

        if ($amount > $downPayment->remaining_amount) {
            throw new \Exception('Jumlah melebihi sisa DP');
        }

        $downPayment->used_amount += $amount;
        $downPayment->updateRemaining();
    }
}
