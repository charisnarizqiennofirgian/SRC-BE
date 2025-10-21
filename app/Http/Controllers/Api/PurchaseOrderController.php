<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
{
    $query = PurchaseOrder::with('supplier')->latest();

    if ($request->has('type')) {
        $query->where('type', $request->type);
    }

    if ($request->has('search')) {
        $query->where('po_number', 'like', '%' . $request->search . '%');
    }
    
    $orders = $query->paginate(15);
    return response()->json(['success' => true, 'data' => $orders]);
}

    public function store(Request $request)
    {
        $validator = $this->validatePurchaseOrder($request);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();
            
            // ✅ FIX: Ganti apply_ppn jadi ppn_percentage
            $totals = $this->calculateTotals($validatedData['details'], $validatedData['ppn_percentage']);

            $order = PurchaseOrder::create([
                'po_number' => $this->generatePoNumber(),
                'supplier_id' => $validatedData['supplier_id'],
                'order_date' => $validatedData['order_date'],
                'status' => 'Open',
                'notes' => $validatedData['notes'] ?? null,
                'type' => $validatedData['type'],
                'subtotal' => $totals['subtotal'],
                'ppn_percentage' => $totals['ppn_percentage'],
                'ppn_amount' => $totals['ppn_amount'],
                'grand_total' => $totals['grand_total'],
            ]);

            $order->details()->createMany($this->prepareDetails($validatedData['details']));
            
            DB::commit(); 
            
            return response()->json([
                'success' => true, 
                'message' => 'Pesanan Pembelian berhasil dibuat.', 
                'data' => $order->load('supplier', 'details.item')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack(); 
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
        }
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load('supplier', 'details.item.unit');
        
        // Parse specifications dari JSON string ke array
        $purchaseOrder->details->each(function($detail) {
            if ($detail->specifications) {
                $detail->specifications = is_string($detail->specifications) 
                    ? json_decode($detail->specifications, true) 
                    : $detail->specifications;
            }
        });
        
        return response()->json(['success' => true, 'data' => $purchaseOrder]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'Open') {
            return response()->json(['success' => false, 'message' => 'Hanya PO dengan status Open yang bisa diupdate.'], 400);
        }
        
        $validator = $this->validatePurchaseOrder($request);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();

            // ✅ FIX: Ganti apply_ppn jadi ppn_percentage
            $totals = $this->calculateTotals($validatedData['details'], $validatedData['ppn_percentage']);
            
            $purchaseOrder->update([
                'supplier_id' => $validatedData['supplier_id'],
                'order_date' => $validatedData['order_date'],
                'notes' => $validatedData['notes'] ?? null,
                'type' => $validatedData['type'],
                'subtotal' => $totals['subtotal'],
                'ppn_percentage' => $totals['ppn_percentage'],
                'ppn_amount' => $totals['ppn_amount'],
                'grand_total' => $totals['grand_total'],
            ]);

            $purchaseOrder->details()->delete(); 
            $purchaseOrder->details()->createMany($this->prepareDetails($validatedData['details']));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pesanan Pembelian berhasil diupdate.',
                'data' => $purchaseOrder->load('supplier', 'details.item')
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'Open') {
            return response()->json(['success' => false, 'message' => 'Hanya PO dengan status Open yang bisa dihapus.'], 400);
        }

        DB::beginTransaction();
        try {
            $purchaseOrder->details()->delete();
            $purchaseOrder->delete(); 

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Pesanan Pembelian berhasil dihapus.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
        }
    }

    // ✅ FIX: Ganti apply_ppn jadi ppn_percentage
    private function validatePurchaseOrder(Request $request)
    {
        return Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'notes' => 'nullable|string',
            'type' => 'required|string|in:operasional,karton,kayu',
            'ppn_percentage' => 'required|numeric|in:0,11,12',
            'details' => 'required|array|min:1',
            'details.*.item_id' => 'required|exists:items,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.price' => 'required|numeric|min:0',
            'details.*.specifications' => 'nullable|array',
        ]);
    }

    // ✅ FIX: Ganti parameter boolean jadi float
    private function calculateTotals(array $details, float $ppnPercentage): array
    {
        $subtotal = collect($details)->sum(fn($item) => $item['quantity'] * $item['price']);

        $ppnAmount = $subtotal * ($ppnPercentage / 100); 
        $grandTotal = $subtotal + $ppnAmount;

        return [
            'subtotal' => $subtotal,
            'ppn_percentage' => $ppnPercentage,
            'ppn_amount' => $ppnAmount,
            'grand_total' => $grandTotal,
        ];
    }

    private function prepareDetails(array $details): array
    {
        return collect($details)->map(function ($item) {
            return [
                'item_id' => $item['item_id'],
                'quantity_ordered' => $item['quantity'],
                'price' => $item['price'],
                'subtotal' => $item['quantity'] * $item['price'],
                'specifications' => isset($item['specifications']) ? json_encode($item['specifications']) : null,
            ];
        })->all();
    }

    private function generatePoNumber()
    {
        $prefix = 'PO-' . now()->format('Ym'); 
        $lastOrder = PurchaseOrder::where('po_number', 'like', $prefix . '%')->latest('id')->first();
        $number = 1;
        if ($lastOrder) {
            $number = (int) substr($lastOrder->po_number, -4) + 1;
        }
        return $prefix . '-' . str_pad($number, 4, '0', STR_PAD_LEFT); 
    }
}
