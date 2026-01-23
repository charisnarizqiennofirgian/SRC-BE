<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchasePayment;
use App\Models\PurchaseBill;
use App\Models\PaymentMethod;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PurchasePaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Daftar semua pembayaran hutang dengan pagination
     */
    public function index(Request $request)
    {
        $query = PurchasePayment::with([
            'purchaseBill.supplier:id,name',
            'paymentMethod:id,name',
            'createdBy:id,name'
        ])->orderBy('payment_date', 'desc');

        // Filter by supplier
        if ($request->supplier_id) {
            $query->whereHas('purchaseBill', function($q) use ($request) {
                $q->where('supplier_id', $request->supplier_id);
            });
        }

        // Filter by date range
        if ($request->start_date) {
            $query->whereDate('payment_date', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('payment_date', '<=', $request->end_date);
        }

        $payments = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);
    }

    /**
     * Get outstanding bills (tagihan belum lunas) by supplier
     */
    public function getOutstandingBills(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier ID tidak valid',
                'errors' => $validator->errors()
            ], 422);
        }

        // Ambil tagihan yang TEMPO dan belum lunas
        $bills = PurchaseBill::with(['supplier:id,name', 'details'])
            ->where('supplier_id', $request->supplier_id)
            ->where('payment_type', PurchaseBill::PAYMENT_TEMPO)
            ->whereIn('payment_status', ['UNPAID', 'PARTIAL'])
            ->where('remaining_amount', '>', 0)
            ->orderBy('bill_date', 'asc')
            ->get()
            ->map(function($bill) {
                return [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'bill_date' => $bill->bill_date->format('Y-m-d'),
                    'due_date' => $bill->due_date->format('Y-m-d'),
                    'total_amount' => $bill->total_amount,
                    'paid_amount' => $bill->paid_amount,
                    'remaining_amount' => $bill->remaining_amount,
                    'payment_status' => $bill->payment_status,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $bills
        ]);
    }

    /**
     * Get form data (dropdown options)
     */
    public function getFormData()
    {
        // Payment Methods yang active (Bank/Kas saja)
        $paymentMethods = PaymentMethod::with('account:id,code,name')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'account_id']);

        return response()->json([
            'success' => true,
            'data' => [
                'payment_methods' => $paymentMethods,
            ]
        ]);
    }

    /**
     * Proses pembayaran hutang
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'purchase_bill_id' => 'required|exists:purchase_bills,id',
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:1',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $payment = $this->paymentService->processPayment($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran berhasil diproses',
                'data' => $payment
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Detail pembayaran
     */
    public function show($id)
    {
        $payment = PurchasePayment::with([
            'purchaseBill.supplier',
            'paymentMethod.account',
            'journalEntry.lines.account',
            'createdBy:id,name'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $payment
        ]);
    }
}
