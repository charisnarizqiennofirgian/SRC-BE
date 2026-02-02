<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DownPayment;
use App\Services\DownPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DownPaymentController extends Controller
{
    protected $downPaymentService;

    public function __construct(DownPaymentService $downPaymentService)
    {
        $this->downPaymentService = $downPaymentService;
    }

    public function index(Request $request)
    {
        try {
            $query = DownPayment::with(['buyer', 'salesOrder', 'account', 'createdBy']);

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('buyer_id')) {
                $query->where('buyer_id', $request->buyer_id);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('dp_number', 'like', "%{$search}%")
                      ->orWhereHas('buyer', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      });
                });
            }

            $downPayments = $query->latest()->paginate($request->per_page ?? 15);

            return response()->json($downPayments);
        } catch (\Exception $e) {
            Log::error('Error fetching down payments: ' . $e->getMessage());
            return response()->json(['message' => 'Error fetching down payments'], 500);
        }
    }

    public function getAvailable($buyerId)
    {
        try {
            $downPayments = $this->downPaymentService->getAvailableDownPayments($buyerId);
            return response()->json($downPayments);
        } catch (\Exception $e) {
            Log::error('Error fetching available DPs: ' . $e->getMessage());
            return response()->json(['message' => 'Error fetching available down payments'], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'sales_order_id' => 'required|exists:sales_orders,id',
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'account_id' => 'required|exists:chart_of_accounts,id',
            'exchange_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $downPayment = $this->downPaymentService->receiveDownPayment(
                salesOrderId: $request->sales_order_id,
                paymentDate: $request->payment_date,
                amount: $request->amount,
                accountId: $request->account_id,
                exchangeRate: $request->exchange_rate ?? 1,
                notes: $request->notes,
                userId: auth()->id()
            );

            DB::commit();

            return response()->json([
                'message' => 'Down payment received successfully',
                'data' => $downPayment->load(['buyer', 'salesOrder', 'account', 'journalEntry.lines.account'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating down payment: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $downPayment = DownPayment::with([
                'buyer',
                'salesOrder',
                'account',
                'journalEntry.lines.account',
                'invoicePayments.salesInvoice',
                'createdBy'
            ])->findOrFail($id);

            return response()->json($downPayment);
        } catch (\Exception $e) {
            Log::error('Error fetching down payment: ' . $e->getMessage());
            return response()->json(['message' => 'Down payment not found'], 404);
        }
    }

    public function destroy($id)
    {
        try {
            $downPayment = DownPayment::findOrFail($id);

            if ($downPayment->used_amount > 0) {
                return response()->json(['message' => 'Cannot delete down payment that has been used'], 400);
            }

            $downPayment->delete();

            return response()->json(['message' => 'Down payment deleted successfully']);

        } catch (\Exception $e) {
            Log::error('Error deleting down payment: ' . $e->getMessage());
            return response()->json(['message' => 'Error deleting down payment'], 500);
        }
    }
}
