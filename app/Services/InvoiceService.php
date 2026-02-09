<?php

namespace App\Services;

use App\Models\SalesInvoice;
use App\Models\SalesInvoiceDetail;
use App\Models\DeliveryOrder;
use App\Models\DownPayment;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    protected $journalService;
    protected $downPaymentService;

    public function __construct(
        JournalService $journalService,
        DownPaymentService $downPaymentService
    ) {
        $this->journalService = $journalService;
        $this->downPaymentService = $downPaymentService;
    }

    public function createInvoiceFromDeliveryOrder(
        int $deliveryOrderId,
        string $invoiceDate,
        float $exchangeRate,
        ?string $dueDate = null,
        ?string $notes = null,
        ?int $userId = null
    ): SalesInvoice {
        $deliveryOrder = DeliveryOrder::with([
            'salesOrder',
            'buyer',
            'details.item',
            'details.salesOrderDetail'
        ])->findOrFail($deliveryOrderId);

        if ($deliveryOrder->status !== 'DELIVERED') {
            throw new \Exception('Delivery Order belum DELIVERED');
        }

        $existingInvoice = SalesInvoice::where('delivery_order_id', $deliveryOrderId)->first();
        if ($existingInvoice) {
            throw new \Exception('Delivery Order sudah memiliki invoice');
        }

        $currency = $deliveryOrder->salesOrder->currency ?? 'IDR';

        $subtotalOriginal = 0;

        foreach ($deliveryOrder->details as $detail) {
            $soDetail = $detail->salesOrderDetail;

            Log::info('Processing DO Detail:', [
                'item_name' => $detail->item_name,
                'quantity_shipped' => $detail->quantity_shipped,
                'unit_price' => $soDetail->unit_price ?? 'NULL',
                'soDetail_exists' => $soDetail ? 'YES' : 'NO',
            ]);

            if (!$soDetail) {
                throw new \Exception("SO Detail tidak ditemukan untuk item: {$detail->item_name}");
            }

            $lineTotal = $detail->quantity_shipped * $soDetail->unit_price;
            $subtotalOriginal += $lineTotal;
        }

        Log::info('Subtotal Calculation:', [
            'subtotalOriginal' => $subtotalOriginal,
            'taxRate' => $deliveryOrder->salesOrder->tax_rate ?? 'NULL',
        ]);

        $taxRate = floatval($deliveryOrder->salesOrder->tax_rate ?? 0);
        $taxAmountOriginal = $subtotalOriginal * ($taxRate / 100);
        $totalOriginal = $subtotalOriginal + $taxAmountOriginal;

        $subtotalIdr = $subtotalOriginal * $exchangeRate;
        $taxAmountIdr = $taxAmountOriginal * $exchangeRate;
        $totalIdr = $totalOriginal * $exchangeRate;

        // ✅ CEK DAN APPLY DOWN PAYMENT
        $availableDownPayments = $this->downPaymentService->getAvailableDownPayments($deliveryOrder->buyer_id);

        $totalDpApplied = 0;
        $dpAllocations = [];

        foreach ($availableDownPayments as $dp) {
            $remainingInvoice = $totalIdr - $totalDpApplied;

            if ($remainingInvoice <= 0) {
                break; // Invoice sudah lunas dari DP
            }

            $dpToUse = min($dp->remaining_amount, $remainingInvoice);

            if ($dpToUse > 0) {
                $dpAllocations[] = [
                    'down_payment_id' => $dp->id,
                    'amount_used' => $dpToUse
                ];
                $totalDpApplied += $dpToUse;
            }
        }

        Log::info('Down Payment Application:', [
            'totalIdr' => $totalIdr,
            'totalDpApplied' => $totalDpApplied,
            'dpAllocations' => $dpAllocations,
        ]);

        Log::info('Final Calculation:', [
            'subtotalOriginal' => $subtotalOriginal,
            'taxRate' => $taxRate,
            'taxAmountOriginal' => $taxAmountOriginal,
            'totalOriginal' => $totalOriginal,
            'exchangeRate' => $exchangeRate,
            'subtotalIdr' => $subtotalIdr,
            'taxAmountIdr' => $taxAmountIdr,
            'totalIdr' => $totalIdr,
            'totalDpApplied' => $totalDpApplied,
            'remainingAmount' => $totalIdr - $totalDpApplied,
        ]);

        $invoiceNumber = SalesInvoice::generateInvoiceNumber();

        $invoice = SalesInvoice::create([
            'invoice_number' => $invoiceNumber,
            'sales_order_id' => $deliveryOrder->sales_order_id,
            'delivery_order_id' => $deliveryOrder->id,
            'buyer_id' => $deliveryOrder->buyer_id,
            'user_id' => $userId ?? auth()->id(),
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'subtotal_currency' => $subtotalOriginal,
            'tax_amount_currency' => $taxAmountOriginal,
            'total_currency' => $totalOriginal,
            'subtotal_idr' => $subtotalIdr,
            'tax_amount_idr' => $taxAmountIdr,
            'total_idr' => $totalIdr,
            'paid_amount' => $totalDpApplied, // ✅ APPLIED DP
            'remaining_amount' => $totalIdr - $totalDpApplied, // ✅ SISA SETELAH DP
            'payment_status' => $totalDpApplied >= $totalIdr ? 'PAID' : ($totalDpApplied > 0 ? 'PARTIAL' : 'UNPAID'), // ✅ AUTO STATUS
            'notes' => $notes,
            'status' => 'DRAFT',
        ]);

        // ✅ APPLY DOWN PAYMENT & UPDATE STATUS
        foreach ($dpAllocations as $allocation) {
            $dp = DownPayment::find($allocation['down_payment_id']);

            // Update DP using service method
            $this->downPaymentService->useDownPayment($dp, $allocation['amount_used']);

            // Update status
            if ($dp->remaining_amount <= 0) {
                $dp->update(['status' => 'FULLY_USED']);
            } else {
                $dp->update(['status' => 'PARTIALLY_USED']);
            }

            $dp->save();

            Log::info('DP Applied:', [
                'dp_id' => $dp->id,
                'dp_number' => $dp->dp_number,
                'amount_used' => $allocation['amount_used'],
                'remaining_after' => $dp->remaining_amount,
                'status' => $dp->status,
            ]);
        }

        foreach ($deliveryOrder->details as $detail) {
            $soDetail = $detail->salesOrderDetail;

            $unitPriceOriginal = $soDetail->unit_price;
            $subtotalOriginal = $unitPriceOriginal * $detail->quantity_shipped;

            $unitPriceIdr = $unitPriceOriginal * $exchangeRate;
            $subtotalIdr = $subtotalOriginal * $exchangeRate;

            SalesInvoiceDetail::create([
                'sales_invoice_id' => $invoice->id,
                'sales_order_detail_id' => $detail->sales_order_detail_id,
                'delivery_order_detail_id' => $detail->id,
                'item_id' => $detail->item_id,
                'item_name' => $detail->item_name,
                'item_code' => $soDetail->item_code,
                'item_unit' => $detail->item_unit,
                'quantity' => $detail->quantity_shipped,
                'unit_price_original' => $unitPriceOriginal,
                'discount_original' => 0,
                'subtotal_original' => $subtotalOriginal,
                'unit_price_idr' => $unitPriceIdr,
                'discount_idr' => 0,
                'subtotal_idr' => $subtotalIdr,
                'unit_cost' => null,
                'total_cost' => null,
            ]);
        }

        return $invoice->fresh(['details', 'buyer', 'salesOrder', 'deliveryOrder']);
    }

    public function postInvoice(SalesInvoice $invoice): void
    {
        if ($invoice->status === 'POSTED') {
            throw new \Exception('Invoice sudah di-posting');
        }

        $buyer = $invoice->buyer;
        if (!$buyer->receivable_account_id) {
            throw new \Exception('Buyer belum memiliki akun piutang');
        }

        $receivableAccount = \App\Models\ChartOfAccount::where('id', $buyer->receivable_account_id)
            ->where('is_active', 1)
            ->first();

        if (!$receivableAccount) {
            throw new \Exception("Akun Piutang (ID: {$buyer->receivable_account_id}) tidak ditemukan atau tidak aktif.");
        }

        $salesAccount = \App\Models\ChartOfAccount::where(function($query) {
                $query->where('code', 'like', '4%')
                      ->orWhere('type', 'PENDAPATAN');
            })
            ->where('is_active', 1)
            ->first();

        if (!$salesAccount) {
            throw new \Exception('Akun Penjualan tidak ditemukan. Pastikan ada akun Pendapatan yang aktif');
        }

        $ppnAccount = \App\Models\ChartOfAccount::where('code', 'like', '2-%')
            ->where('name', 'like', '%PPN%')
            ->where('is_active', 1)
            ->first();

        // ✅ JURNAL UNTUK INVOICE (Piutang dikurangi DP yang sudah dibayar)
        $entries = [
            [
                'account_id' => $receivableAccount->id,
                'debit' => floatval($invoice->remaining_amount), // ✅ HANYA SISA SETELAH DP
                'credit' => 0,
                'description' => "Piutang - {$invoice->invoice_number}",
            ],
            [
                'account_id' => $salesAccount->id,
                'debit' => 0,
                'credit' => floatval($invoice->subtotal_idr),
                'description' => "Penjualan - {$invoice->invoice_number}",
            ],
        ];

        if ($invoice->tax_amount_idr > 0 && $ppnAccount) {
            $entries[] = [
                'account_id' => $ppnAccount->id,
                'debit' => 0,
                'credit' => floatval($invoice->tax_amount_idr),
                'description' => "PPN Keluaran - {$invoice->invoice_number}",
            ];
        }

        // ✅ JURNAL UNTUK DP YANG DIPAKAI
        if ($invoice->paid_amount > 0) {
            $depositAccount = \App\Models\ChartOfAccount::where('code', 'like', '2-1%')
                ->where(function($q) {
                    $q->where('name', 'like', '%Uang Muka Penjualan%')
                      ->orWhere('name', 'like', '%Customer Deposit%');
                })
                ->first();

            if ($depositAccount) {
                $entries[] = [
                    'account_id' => $depositAccount->id,
                    'debit' => floatval($invoice->paid_amount),
                    'credit' => 0,
                    'description' => "Pelunasan Uang Muka - {$invoice->invoice_number}",
                ];
            }
        }

        $totalDebit = array_sum(array_column($entries, 'debit'));
        $totalCredit = array_sum(array_column($entries, 'credit'));

        Log::info('=== BEFORE SEND TO JOURNAL SERVICE ===', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_total_idr' => $invoice->total_idr,
            'invoice_paid_amount' => $invoice->paid_amount,
            'invoice_remaining_amount' => $invoice->remaining_amount,
            'receivable_account_id' => $receivableAccount->id,
            'sales_account_id' => $salesAccount->id,
            'entries' => $entries,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balance' => ($totalDebit === $totalCredit),
        ]);

        if ($totalDebit != $totalCredit) {
            throw new \Exception("Entries tidak balance SEBELUM kirim ke JournalService! Debit: Rp " . number_format($totalDebit, 2, ',', '.') . ", Kredit: Rp " . number_format($totalCredit, 2, ',', '.'));
        }

        try {
            $journalEntry = $this->journalService->createJournal(
                date: $invoice->invoice_date,
                description: "Invoice Penjualan - {$invoice->invoice_number}",
                entries: $entries,
                referenceType: 'sales_invoice',
                referenceId: $invoice->id
            );
        } catch (\Exception $e) {
            Log::error('JournalService Error:', [
                'message' => $e->getMessage(),
                'entries_sent' => $entries,
            ]);
            throw $e;
        }

        $invoice->update([
            'journal_entry_id' => $journalEntry->id,
            'status' => 'POSTED',
        ]);
    }

    public function cancelInvoice(SalesInvoice $invoice): void
    {
        if ($invoice->payment_status !== 'UNPAID') {
            throw new \Exception('Invoice yang sudah ada pembayaran tidak bisa dibatalkan');
        }

        if ($invoice->journal_entry_id) {
            $this->journalService->reverseJournal($invoice->journalEntry);
        }

        $invoice->update([
            'status' => 'CANCELLED',
            'journal_entry_id' => null,
        ]);
    }

    public function updatePaymentStatus(SalesInvoice $invoice): void
    {
        $totalPaid = $invoice->paid_amount;
        $grandTotal = $invoice->total_idr;
        $remaining = $grandTotal - $totalPaid;

        if ($remaining <= 0) {
            $paymentStatus = 'PAID';
        } elseif ($totalPaid > 0) {
            $paymentStatus = 'PARTIAL';
        } else {
            $paymentStatus = 'UNPAID';
        }

        $invoice->update([
            'remaining_amount' => max(0, $remaining),
            'payment_status' => $paymentStatus,
        ]);
    }
}
