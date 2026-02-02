<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvoicePayment;
use App\Services\InvoicePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoicePaymentController extends Controller
{
    protected $invoicePaymentService;

    public function __construct(InvoicePaymentService $invoicePaymentService)
    {
        $this->invoicePaymentService = $invoicePaymentService;
    }

    public function index(Request $request)
    {
        try {
            $query = InvoicePayment::with([
                'salesInvoice',
                'buyer',
                'account',  // ← GANTI dari 'paymentMethod'
                'downPayment',
                'createdBy'
            ]);

            if ($request->has('sales_invoice_id')) {
                $query->where('sales_invoice_id', $request->sales_invoice_id);
            }

            if ($request->has('buyer_id')) {
                $query->where('buyer_id', $request->buyer_id);
            }

            if ($request->has('payment_type')) {
                $query->where('payment_type', $request->payment_type);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('payment_number', 'like', "%{$search}%")
                      ->orWhereHas('buyer', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      });
                });
            }

            $payments = $query->latest()->paginate($request->per_page ?? 15);

            return response()->json($payments);
        } catch (\Exception $e) {
            Log::error('Error fetching invoice payments: ' . $e->getMessage());
            return response()->json(['message' => 'Error fetching invoice payments'], 500);
        }
    }

    public function receiveCashPayment(Request $request)
    {
        $request->validate([
            'sales_invoice_id' => 'required|exists:sales_invoices,id',
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'account_id' => 'required|exists:chart_of_accounts,id',  // ← GANTI dari 'payment_method_id'
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $payment = $this->invoicePaymentService->receiveCashPayment(
                salesInvoiceId: $request->sales_invoice_id,
                paymentDate: $request->payment_date,
                amount: $request->amount,
                accountId: $request->account_id,  // ← GANTI dari paymentMethodId
                notes: $request->notes,
                userId: auth()->id()
            );

            DB::commit();

            return response()->json([
                'message' => 'Cash payment received successfully',
                'data' => $payment->load([
                    'salesInvoice',
                    'buyer',
                    'account',  // ← GANTI dari 'paymentMethod'
                    'journalEntry.details'
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error receiving cash payment: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function receiveDownPaymentDeduction(Request $request)
    {
        $request->validate([
            'sales_invoice_id' => 'required|exists:sales_invoices,id',
            'down_payment_id' => 'required|exists:down_payments,id',
            'amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $payment = $this->invoicePaymentService->receiveDownPaymentDeduction(
                salesInvoiceId: $request->sales_invoice_id,
                downPaymentId: $request->down_payment_id,
                amount: $request->amount,
                notes: $request->notes,
                userId: auth()->id()
            );

            DB::commit();

            return response()->json([
                'message' => 'Down payment deduction applied successfully',
                'data' => $payment->load([
                    'salesInvoice',
                    'buyer',
                    'downPayment',
                    'journalEntry.details'
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error applying DP deduction: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $payment = InvoicePayment::with([
                'salesInvoice',
                'buyer',
                'account',  // ← GANTI dari 'paymentMethod'
                'downPayment',
                'journalEntry.details',
                'createdBy'
            ])->findOrFail($id);

            return response()->json($payment);
        } catch (\Exception $e) {
            Log::error('Error fetching invoice payment: ' . $e->getMessage());
            return response()->json(['message' => 'Invoice payment not found'], 404);
        }
    }

    public function destroy($id)
    {
        try {
            $payment = InvoicePayment::findOrFail($id);

            if ($payment->isFromDownPayment()) {
                $dp = $payment->downPayment;
                $dp->used_amount -= $payment->amount;
                $dp->updateRemaining();
            }

            $payment->delete();

            return response()->json(['message' => 'Invoice payment deleted successfully']);

        } catch (\Exception $e) {
            Log::error('Error deleting invoice payment: ' . $e->getMessage());
            return response()->json(['message' => 'Error deleting invoice payment'], 500);
        }
    }
}
