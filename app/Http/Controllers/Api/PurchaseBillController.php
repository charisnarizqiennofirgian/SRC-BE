<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseBill;
use App\Models\GoodsReceiptDetail;
use App\Models\ChartOfAccount;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PurchaseBillController extends Controller
{
    protected $journalService;

    public function __construct(JournalService $journalService)
    {
        $this->journalService = $journalService;
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,id',
            'supplier_invoice_number' => 'required|string|max:255',
            'bill_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:bill_date',
            'notes' => 'nullable|string',
            'ppn_percentage' => 'nullable|numeric|min:0|max:100',

            // ✅ GANTI: 1 COA untuk semuanya
            'coa_id' => 'required|exists:chart_of_accounts,id',

            // ✅ GANTI: Details tidak perlu account_id per item
            'details' => 'required|array|min:1',
            'details.*.goods_receipt_detail_id' => 'nullable|exists:goods_receipt_details,id',
            'details.*.item_id' => 'required|exists:items,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.price' => 'required|numeric|min:0',

            // ✅ GANTI: Payment type saja, tidak perlu payment_method_id
            'payment_type' => 'required|in:TEMPO,TUNAI,DP',
            'paid_amount' => 'required_if:payment_type,DP|nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();

            // Hitung subtotal
            $subtotal = 0;
            foreach ($validatedData['details'] as $detail) {
                $subtotal += $detail['quantity'] * $detail['price'];
            }

            // Hitung PPN
            $ppnPercentage = $validatedData['ppn_percentage'] ?? 0;
            $ppnAmount = $subtotal * ($ppnPercentage / 100);
            $totalAmount = $subtotal + $ppnAmount;

            // Hitung paid_amount dan remaining_amount berdasarkan payment_type
            $paidAmount = 0;
            $remainingAmount = $totalAmount;

            switch ($validatedData['payment_type']) {
                case 'TUNAI':
                    $paidAmount = $totalAmount;
                    $remainingAmount = 0;
                    break;
                case 'DP':
                    $paidAmount = $validatedData['paid_amount'] ?? 0;
                    $remainingAmount = $totalAmount - $paidAmount;
                    break;
                case 'TEMPO':
                default:
                    $paidAmount = 0;
                    $remainingAmount = $totalAmount;
                    break;
            }

            // Simpan Purchase Bill
            $purchaseBill = PurchaseBill::create([
                'supplier_id' => $validatedData['supplier_id'],
                'bill_number' => $this->generateBillNumber(),
                'supplier_invoice_number' => $validatedData['supplier_invoice_number'],
                'bill_date' => $validatedData['bill_date'],
                'due_date' => $validatedData['due_date'],
                'subtotal' => $subtotal,
                'ppn_percentage' => $ppnPercentage,
                'ppn_amount' => $ppnAmount,
                'total_amount' => $totalAmount,
                'status' => 'Posted',
                'payment_type' => $validatedData['payment_type'],
                'coa_id' => $validatedData['coa_id'],  // ✅ Simpan COA yang dipilih
                'paid_amount' => $paidAmount,
                'remaining_amount' => $remainingAmount,
                'notes' => $validatedData['notes'] ?? null,
            ]);

            // Simpan Details (tanpa account_id per item)
            foreach ($validatedData['details'] as $detail) {
                $goodsReceiptDetail = null;
                $specifications = null;

                if (!empty($detail['goods_receipt_detail_id'])) {
                    $goodsReceiptDetail = GoodsReceiptDetail::with('purchaseOrderDetail')
                        ->find($detail['goods_receipt_detail_id']);

                    if ($goodsReceiptDetail && $goodsReceiptDetail->purchaseOrderDetail) {
                        $specifications = $goodsReceiptDetail->purchaseOrderDetail->specifications;
                    }
                }

                $purchaseBill->details()->create([
                    'goods_receipt_detail_id' => $detail['goods_receipt_detail_id'] ?? null,
                    'item_id' => $detail['item_id'],
                    'quantity' => $detail['quantity'],
                    'price' => $detail['price'],
                    'subtotal' => $detail['quantity'] * $detail['price'],
                    'specifications' => $specifications,
                ]);

                // Update status billed di goods receipt detail
                if ($goodsReceiptDetail) {
                    $goodsReceiptDetail->update(['billed' => true]);
                }
            }

            // Load relasi untuk jurnal
            $purchaseBill->load(['supplier', 'details.item', 'coa']);

            // Buat jurnal otomatis
            $journal = $this->journalService->createFromPurchaseBill($purchaseBill);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Faktur Pembelian berhasil disimpan.',
                'data' => $purchaseBill->load(['supplier', 'details.item', 'coa', 'journalEntry.lines.account'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index()
    {
        $bills = PurchaseBill::with(['supplier:id,name', 'coa:id,code,name'])
            ->whereNull('deleted_at')
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $bills
        ]);
    }

    public function show($id)
    {
        try {
            $purchaseBill = PurchaseBill::with([
                'supplier',
                'details.item',
                'details.goodsReceiptDetail.purchaseOrderDetail',
                'coa',
                'journalEntry.lines.account'
            ])
            ->whereNull('deleted_at')
            ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $purchaseBill
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => "Faktur dengan ID {$id} tidak ditemukan atau sudah dihapus."
            ], 404);
        }
    }

    /**
     * Get data untuk dropdown di form
     */
    public function getFormData()
    {
        // COA untuk pilihan akun (Persediaan & Biaya)
        $coaAccounts = ChartOfAccount::whereIn('type', ['ASET', 'BIAYA', 'KEWAJIBAN'])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        // Payment Types
        $paymentTypes = PurchaseBill::getPaymentTypes();

        return response()->json([
            'success' => true,
            'data' => [
                'coa_accounts' => $coaAccounts,
                'payment_types' => $paymentTypes,
            ]
        ]);
    }

    private function generateBillNumber()
    {
        $prefix = 'BILL-' . now()->format('Ym');

        $lastBill = PurchaseBill::where('bill_number', 'like', $prefix . '%')
            ->latest('id')
            ->first();

        $number = 1;
        if ($lastBill) {
            $number = (int) substr($lastBill->bill_number, -4) + 1;
        }

        return $prefix . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}
