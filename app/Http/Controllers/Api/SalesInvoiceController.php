<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use App\Models\DeliveryOrder;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesInvoiceController extends Controller
{
    protected $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    /**
     * Display a listing of sales invoices
     */
    public function index(Request $request)
    {
        try {
            $query = SalesInvoice::with(['buyer', 'salesOrder', 'deliveryOrder', 'user']);

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by payment status
            if ($request->has('payment_status')) {
                $query->where('payment_status', $request->payment_status);
            }

            // Filter by buyer
            if ($request->has('buyer_id')) {
                $query->where('buyer_id', $request->buyer_id);
            }

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('invoice_date', [$request->start_date, $request->end_date]);
            }

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                      ->orWhereHas('buyer', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      });
                });
            }

            $invoices = $query->latest()->paginate($request->per_page ?? 15);

            return response()->json($invoices);
        } catch (\Exception $e) {
            Log::error('Error fetching invoices: ' . $e->getMessage());
            return response()->json(['message' => 'Error fetching invoices'], 500);
        }
    }

    /**
     * Get delivery orders yang belum di-invoice
     */
    public function getAvailableDeliveryOrders()
    {
        try {
            $deliveryOrders = DeliveryOrder::with(['salesOrder', 'buyer', 'details.item'])
                ->where('status', 'DELIVERED')
                ->whereDoesntHave('salesInvoice')
                ->latest()
                ->get();

            return response()->json($deliveryOrders);
        } catch (\Exception $e) {
            Log::error('Error fetching available DOs: ' . $e->getMessage());
            return response()->json(['message' => 'Error fetching delivery orders'], 500);
        }
    }

    /**
     * Store a newly created invoice
     */
    public function store(Request $request)
    {
        $request->validate([
            'delivery_order_id' => 'required|exists:delivery_orders,id',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'exchange_rate' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $invoice = $this->invoiceService->createInvoiceFromDeliveryOrder(
                $request->delivery_order_id,
                $request->invoice_date,
                $request->exchange_rate,
                $request->due_date,
                $request->notes,
                auth()->id()
            );

            DB::commit();

            return response()->json([
                'message' => 'Invoice created successfully',
                'data' => $invoice->load(['buyer', 'salesOrder', 'deliveryOrder', 'details'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating invoice: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified invoice
     */
    public function show($id)
    {
        try {
            $invoice = SalesInvoice::with([
                'buyer',
                'salesOrder',
                'deliveryOrder',
                'details.item',
                'user',
                'journalEntry.details'
            ])->findOrFail($id);

            return response()->json($invoice);
        } catch (\Exception $e) {
            Log::error('Error fetching invoice: ' . $e->getMessage());
            return response()->json(['message' => 'Invoice not found'], 404);
        }
    }

    /**
     * Post invoice (buat jurnal)
     */
    public function post($id)
    {
        try {
            DB::beginTransaction();

            $invoice = SalesInvoice::findOrFail($id);

            if ($invoice->status === 'POSTED') {
                return response()->json(['message' => 'Invoice already posted'], 400);
            }

            $this->invoiceService->postInvoice($invoice);

            DB::commit();

            return response()->json([
                'message' => 'Invoice posted successfully',
                'data' => $invoice->load(['journalEntry.details'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error posting invoice: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Cancel invoice
     */
    public function cancel($id)
    {
        try {
            DB::beginTransaction();

            $invoice = SalesInvoice::findOrFail($id);

            if ($invoice->payment_status !== 'UNPAID') {
                return response()->json(['message' => 'Cannot cancel invoice with payments'], 400);
            }

            $this->invoiceService->cancelInvoice($invoice);

            DB::commit();

            return response()->json(['message' => 'Invoice cancelled successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error cancelling invoice: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete invoice (soft delete)
     */
    public function destroy($id)
    {
        try {
            $invoice = SalesInvoice::findOrFail($id);

            if ($invoice->status === 'POSTED') {
                return response()->json(['message' => 'Cannot delete posted invoice'], 400);
            }

            $invoice->delete();

            return response()->json(['message' => 'Invoice deleted successfully']);

        } catch (\Exception $e) {
            Log::error('Error deleting invoice: ' . $e->getMessage());
            return response()->json(['message' => 'Error deleting invoice'], 500);
        }
    }
}
