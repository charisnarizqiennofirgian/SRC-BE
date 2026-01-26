<?php

namespace App\Services;

use App\Models\SalesInvoice;
use App\Models\SalesInvoiceDetail;
use App\Models\DeliveryOrder;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    protected $journalService;

    public function __construct(JournalService $journalService)
    {
        $this->journalService = $journalService;
    }

    /**
     * Buat invoice dari Delivery Order
     */
    public function createInvoiceFromDeliveryOrder(
        int $deliveryOrderId,
        string $invoiceDate,
        float $exchangeRate,
        ?string $dueDate = null,
        ?string $notes = null,
        ?int $userId = null
    ): SalesInvoice {
        // Ambil data DO
        $deliveryOrder = DeliveryOrder::with([
            'salesOrder',
            'buyer',
            'details.item',
            'details.salesOrderDetail'
        ])->findOrFail($deliveryOrderId);

        // Validasi DO sudah DELIVERED
        if ($deliveryOrder->status !== 'DELIVERED') {
            throw new \Exception('Delivery Order belum DELIVERED');
        }

        // Validasi DO belum ada invoice
        $existingInvoice = SalesInvoice::where('delivery_order_id', $deliveryOrderId)->first();
        if ($existingInvoice) {
            throw new \Exception('Delivery Order sudah memiliki invoice');
        }

        // Ambil currency dari SO
        $currency = $deliveryOrder->salesOrder->currency ?? 'IDR';

        // Hitung total
        $subtotalOriginal = 0;
        $discountOriginal = 0;

        foreach ($deliveryOrder->details as $detail) {
            $soDetail = $detail->salesOrderDetail;
            $lineTotal = $detail->quantity_shipped * $soDetail->unit_price;
            $lineDiscount = $detail->quantity_shipped * ($soDetail->discount ?? 0);

            $subtotalOriginal += $lineTotal;
            $discountOriginal += $lineDiscount;
        }

        $netSubtotal = $subtotalOriginal - $discountOriginal;

        // PPN 11% (jika ada)
        $taxPpnOriginal = $netSubtotal * 0.11;
        $grandTotalOriginal = $netSubtotal + $taxPpnOriginal;

        // Konversi ke IDR
        $subtotalIdr = $subtotalOriginal * $exchangeRate;
        $discountIdr = $discountOriginal * $exchangeRate;
        $taxPpnIdr = $taxPpnOriginal * $exchangeRate;
        $grandTotalIdr = $grandTotalOriginal * $exchangeRate;

        // Generate invoice number
        $invoiceNumber = SalesInvoice::generateInvoiceNumber();

        // Buat invoice
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
            'subtotal_original' => $subtotalOriginal,
            'discount_original' => $discountOriginal,
            'tax_ppn_original' => $taxPpnOriginal,
            'grand_total_original' => $grandTotalOriginal,
            'subtotal_idr' => $subtotalIdr,
            'discount_idr' => $discountIdr,
            'tax_ppn_idr' => $taxPpnIdr,
            'grand_total_idr' => $grandTotalIdr,
            'paid_amount' => 0,
            'remaining_amount' => $grandTotalIdr,
            'payment_status' => 'UNPAID',
            'notes' => $notes,
            'status' => 'DRAFT',
        ]);

        // Buat invoice details
        foreach ($deliveryOrder->details as $detail) {
            $soDetail = $detail->salesOrderDetail;

            $unitPriceOriginal = $soDetail->unit_price;
            $discountOriginal = $soDetail->discount ?? 0;
            $subtotalOriginal = ($unitPriceOriginal - $discountOriginal) * $detail->quantity_shipped;

            // Konversi ke IDR
            $unitPriceIdr = $unitPriceOriginal * $exchangeRate;
            $discountIdr = $discountOriginal * $exchangeRate;
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
                'discount_original' => $discountOriginal,
                'subtotal_original' => $subtotalOriginal,
                'unit_price_idr' => $unitPriceIdr,
                'discount_idr' => $discountIdr,
                'subtotal_idr' => $subtotalIdr,
                'unit_cost' => null, // Bisa diisi nanti dari HPP
                'total_cost' => null,
            ]);
        }

        return $invoice->fresh(['details', 'buyer', 'salesOrder', 'deliveryOrder']);
    }

    /**
     * Post invoice - buat jurnal piutang
     */
    public function postInvoice(SalesInvoice $invoice): void
    {
        if ($invoice->status === 'POSTED') {
            throw new \Exception('Invoice sudah di-posting');
        }

        // Ambil akun piutang buyer
        $buyer = $invoice->buyer;
        if (!$buyer->receivable_account_id) {
            throw new \Exception('Buyer belum memiliki akun piutang');
        }

        // Cari akun penjualan (asumsi kode 4-1001 atau sejenisnya)
        $salesAccount = \App\Models\ChartOfAccount::where('code', 'like', '4-1%')
            ->where('account_type', 'REVENUE')
            ->first();

        if (!$salesAccount) {
            throw new \Exception('Akun Penjualan tidak ditemukan');
        }

        // Cari akun PPN Keluaran (asumsi kode 2-1004 atau sejenisnya)
        $ppnAccount = \App\Models\ChartOfAccount::where('code', 'like', '2-1004%')
            ->orWhere('account_name', 'like', '%PPN Keluaran%')
            ->first();

        // Prepare jurnal entries
        $entries = [
            [
                'account_id' => $buyer->receivable_account_id,
                'debit' => $invoice->grand_total_idr,
                'credit' => 0,
                'description' => "Piutang - {$invoice->invoice_number}",
            ],
            [
                'account_id' => $salesAccount->id,
                'debit' => 0,
                'credit' => $invoice->subtotal_idr - $invoice->discount_idr,
                'description' => "Penjualan - {$invoice->invoice_number}",
            ],
        ];

        // Tambah PPN jika ada
        if ($invoice->tax_ppn_idr > 0 && $ppnAccount) {
            $entries[] = [
                'account_id' => $ppnAccount->id,
                'debit' => 0,
                'credit' => $invoice->tax_ppn_idr,
                'description' => "PPN Keluaran - {$invoice->invoice_number}",
            ];
        }

        // Buat jurnal
        $journalEntry = $this->journalService->createJournal(
            date: $invoice->invoice_date,
            description: "Invoice Penjualan - {$invoice->invoice_number}",
            entries: $entries,
            referenceType: 'sales_invoice',
            referenceId: $invoice->id
        );

        // Update invoice
        $invoice->update([
            'journal_entry_id' => $journalEntry->id,
            'status' => 'POSTED',
        ]);
    }

    /**
     * Cancel invoice
     */
    public function cancelInvoice(SalesInvoice $invoice): void
    {
        if ($invoice->payment_status !== 'UNPAID') {
            throw new \Exception('Invoice yang sudah ada pembayaran tidak bisa dibatalkan');
        }

        // Jika sudah ada jurnal, reverse jurnal
        if ($invoice->journal_entry_id) {
            $this->journalService->reverseJournal($invoice->journalEntry);
        }

        // Update status
        $invoice->update([
            'status' => 'CANCELLED',
            'journal_entry_id' => null,
        ]);
    }

    /**
     * Update payment status berdasarkan pembayaran
     */
    public function updatePaymentStatus(SalesInvoice $invoice): void
    {
        $totalPaid = $invoice->paid_amount;
        $grandTotal = $invoice->grand_total_idr;
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
