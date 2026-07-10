<?php

namespace App\Services;

use App\Models\SalesInvoice;
use App\Models\SalesInvoiceDetail;
use App\Models\DeliveryOrder;
use App\Models\DownPayment;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\Auth;
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

    /**
     * Satu DO bisa menggabungkan beberapa SO (lihat DeliveryOrderController::store()), tapi
     * penagihan tetap per-SO (buyer bayar per SO, bukan per pengiriman). Jadi dari satu DO bisa
     * lahir >1 invoice — satu per SO yang terlibat, masing-masing cuma berisi baris yang
     * berasal dari SO itu. Kalau sebagian SO dalam DO ini sudah pernah di-invoice sebelumnya
     * (lihat cek per-detail_id di bawah), SO itu di-skip, cuma SO yang belum yang dibuatkan
     * invoice baru.
     *
     * @return \Illuminate\Support\Collection<int, SalesInvoice>
     */
    public function createInvoicesFromDeliveryOrder(
        int $deliveryOrderId,
        string $invoiceDate,
        float $exchangeRate,
        ?string $dueDate = null,
        ?string $notes = null,
        ?int $userId = null
    ) {
        $deliveryOrder = DeliveryOrder::with([
            'buyer',
            'details.item',
            'details.salesOrderDetail.salesOrder',
        ])->findOrFail($deliveryOrderId);

        if ($deliveryOrder->status !== 'DELIVERED') {
            throw new \Exception('Delivery Order belum DELIVERED');
        }

        $alreadyInvoicedDetailIds = SalesInvoiceDetail::whereIn(
            'delivery_order_detail_id',
            $deliveryOrder->details->pluck('id')
        )->pluck('delivery_order_detail_id')->all();

        $pendingDetails = $deliveryOrder->details->reject(
            fn ($d) => in_array($d->id, $alreadyInvoicedDetailIds)
        );

        if ($pendingDetails->isEmpty()) {
            throw new \Exception('Semua barang di Delivery Order ini sudah di-invoice');
        }

        $detailsBySo = $pendingDetails->groupBy(fn ($d) => $d->salesOrderDetail->sales_order_id ?? null);

        $createdInvoices = collect();

        foreach ($detailsBySo as $salesOrderId => $soDetails) {
            $salesOrder = $soDetails->first()->salesOrderDetail->salesOrder ?? null;
            if (!$salesOrderId || !$salesOrder) {
                throw new \Exception('SO Detail/Sales Order tidak ditemukan untuk sebagian barang di DO ini');
            }

            $currency = $salesOrder->currency ?? 'IDR';
            $taxRate = floatval($salesOrder->tax_rate ?? 0);

            $subtotalOriginal = 0;
            foreach ($soDetails as $detail) {
                $soDetail = $detail->salesOrderDetail;
                if (!$soDetail) {
                    throw new \Exception("SO Detail tidak ditemukan untuk item: {$detail->item_name}");
                }
                // Pakai harga yang AKTIF sekarang (bukan harga beku di $soDetail, yang bisa
                // usang kalau SO diedit setelah DO dibuat — lihat SalesOrderDetail::resolveCurrent()).
                $currentSoDetail = \App\Models\SalesOrderDetail::resolveCurrent($salesOrderId, $detail->item_id);
                $unitPrice = $currentSoDetail->unit_price ?? $soDetail->unit_price;
                $subtotalOriginal += $detail->quantity_shipped * $unitPrice;
            }

            $taxAmountOriginal = $subtotalOriginal * ($taxRate / 100);
            $totalOriginal = $subtotalOriginal + $taxAmountOriginal;

            $subtotalIdr = $subtotalOriginal * $exchangeRate;
            $taxAmountIdr = $taxAmountOriginal * $exchangeRate;
            $totalIdr = $totalOriginal * $exchangeRate;

            // ✅ CEK DAN APPLY DOWN PAYMENT (dievaluasi ulang tiap iterasi SO — DP yang sudah
            // dipakai invoice SO sebelumnya dalam loop ini otomatis berkurang, sisanya baru
            // dipakai untuk SO berikutnya)
            $availableDownPayments = $this->downPaymentService->getAvailableDownPayments($deliveryOrder->buyer_id);

            $totalDpApplied = 0;
            $dpAllocations = [];

            foreach ($availableDownPayments as $dp) {
                $remainingInvoice = $totalIdr - $totalDpApplied;
                if ($remainingInvoice <= 0) {
                    break;
                }
                $dpToUse = min($dp->remaining_amount, $remainingInvoice);
                if ($dpToUse > 0) {
                    $dpAllocations[] = ['down_payment_id' => $dp->id, 'amount_used' => $dpToUse];
                    $totalDpApplied += $dpToUse;
                }
            }

            Log::info('Invoice dari DO (per-SO):', [
                'delivery_order_id' => $deliveryOrder->id,
                'sales_order_id' => $salesOrderId,
                'subtotalOriginal' => $subtotalOriginal,
                'taxRate' => $taxRate,
                'totalIdr' => $totalIdr,
                'totalDpApplied' => $totalDpApplied,
            ]);

            $invoiceNumber = SalesInvoice::generateInvoiceNumber();

            $invoice = SalesInvoice::create([
                'invoice_number' => $invoiceNumber,
                'sales_order_id' => $salesOrderId,
                'delivery_order_id' => $deliveryOrder->id,
                'buyer_id' => $deliveryOrder->buyer_id,
                'user_id' => $userId ?? Auth::id(),
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
                'paid_amount' => $totalDpApplied,
                'remaining_amount' => $totalIdr - $totalDpApplied,
                'payment_status' => $totalDpApplied >= $totalIdr ? 'PAID' : ($totalDpApplied > 0 ? 'PARTIAL' : 'UNPAID'),
                'notes' => $notes,
                'status' => 'DRAFT',
            ]);

            foreach ($dpAllocations as $allocation) {
                $dp = DownPayment::find($allocation['down_payment_id']);
                $this->downPaymentService->useDownPayment($dp, $allocation['amount_used']);
                $dp->update(['status' => $dp->remaining_amount <= 0 ? 'FULLY_USED' : 'PARTIALLY_USED']);
            }

            foreach ($soDetails as $detail) {
                $soDetail = $detail->salesOrderDetail;
                $currentSoDetail = \App\Models\SalesOrderDetail::resolveCurrent($salesOrderId, $detail->item_id);
                $unitPriceOriginal = $currentSoDetail->unit_price ?? $soDetail->unit_price;
                $lineSubtotalOriginal = $unitPriceOriginal * $detail->quantity_shipped;

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
                    'subtotal_original' => $lineSubtotalOriginal,
                    'unit_price_idr' => $unitPriceOriginal * $exchangeRate,
                    'discount_idr' => 0,
                    'subtotal_idr' => $lineSubtotalOriginal * $exchangeRate,
                    'unit_cost' => null,
                    'total_cost' => null,
                ]);
            }

            $createdInvoices->push($invoice->fresh(['details', 'buyer', 'salesOrder', 'deliveryOrder']));
        }

        return $createdInvoices;
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

        $salesAccount = \App\Models\ChartOfAccount::where('code', '500.01.001')
            ->where('is_active', 1)
            ->first();

        if (!$salesAccount) {
            throw new \Exception('Akun Penjualan tidak ditemukan. Pastikan ada akun Pendapatan yang aktif');
        }

        $ppnAccount = \App\Models\ChartOfAccount::where('code', '312.01.001')
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
            $depositAccount = \App\Models\ChartOfAccount::where('code', 'like', '314.01%')
                ->where('name', 'like', '%UANG MUKA PENJUALAN%')
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
