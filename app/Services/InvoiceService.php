<?php

namespace App\Services;

use App\Models\SalesInvoice;
use App\Models\SalesInvoiceDetail;
use App\Models\DeliveryOrder;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    protected $journalService;

    public function __construct(JournalService $journalService)
    {
        $this->journalService = $journalService;
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
            $lineTotal = $detail->quantity_shipped * $soDetail->unit_price;
            $subtotalOriginal += $lineTotal;
        }

        $taxRate = floatval($deliveryOrder->salesOrder->tax_rate ?? 0);
        $taxAmountOriginal = $subtotalOriginal * ($taxRate / 100);
        $totalOriginal = $subtotalOriginal + $taxAmountOriginal;

        $subtotalIdr = $subtotalOriginal * $exchangeRate;
        $taxAmountIdr = $taxAmountOriginal * $exchangeRate;
        $totalIdr = $totalOriginal * $exchangeRate;

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
            'paid_amount' => 0,
            'remaining_amount' => $totalIdr,
            'payment_status' => 'UNPAID',
            'notes' => $notes,
            'status' => 'DRAFT',
        ]);

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

    // VALIDASI: Cek apakah akun piutang benar-benar ada
    $receivableAccount = \App\Models\ChartOfAccount::where('id', $buyer->receivable_account_id)
        ->where('is_active', 1)
        ->first();

    if (!$receivableAccount) {
        throw new \Exception("Akun Piutang (ID: {$buyer->receivable_account_id}) tidak ditemukan atau tidak aktif. Silakan periksa data buyer.");
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

    $entries = [
        [
            'account_id' => $receivableAccount->id,
            'debit' => $invoice->total_idr,
            'credit' => 0,
            'description' => "Piutang - {$invoice->invoice_number}",
        ],
        [
            'account_id' => $salesAccount->id,
            'debit' => 0,
            'credit' => $invoice->subtotal_idr,
            'description' => "Penjualan - {$invoice->invoice_number}",
        ],
    ];

    if ($invoice->tax_amount_idr > 0 && $ppnAccount) {
        $entries[] = [
            'account_id' => $ppnAccount->id,
            'debit' => 0,
            'credit' => $invoice->tax_amount_idr,
            'description' => "PPN Keluaran - {$invoice->invoice_number}",
        ];
    }

    Log::info('Invoice Post - Debug Info:', [
        'invoice_id' => $invoice->id,
        'receivable_account' => [
            'id' => $receivableAccount->id,
            'code' => $receivableAccount->code,
            'name' => $receivableAccount->name,
        ],
        'sales_account' => [
            'id' => $salesAccount->id,
            'code' => $salesAccount->code,
            'name' => $salesAccount->name,
        ],
        'entries' => $entries,
        'total_debit' => array_sum(array_column($entries, 'debit')),
        'total_credit' => array_sum(array_column($entries, 'credit')),
    ]);

    $journalEntry = $this->journalService->createJournal(
        date: $invoice->invoice_date,
        description: "Invoice Penjualan - {$invoice->invoice_number}",
        entries: $entries,
        referenceType: 'sales_invoice',
        referenceId: $invoice->id
    );

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
