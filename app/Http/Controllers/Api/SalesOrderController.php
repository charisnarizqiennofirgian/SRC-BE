<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SalesOrderController extends Controller
{
    
    public function index(Request $request)
    {
        try {
            $query = SalesOrder::without('details')
                ->with(['buyer:id,name', 'user:id,name'])
                ->select('id', 'so_number', 'buyer_id', 'user_id', 'so_date', 'delivery_date', 'grand_total', 'status', 'currency');

            
            $salesOrders = $query->orderBy('so_date', 'desc')
                                ->orderBy('id', 'desc')
                                ->paginate($request->input('per_page', 25));

            return response()->json([
                'success' => true,
                'data' => $salesOrders
            ], 200);

        } catch (\Exception $e) {
            Log::error('Gagal mengambil daftar Sales Order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data.'
            ], 500);
        }
    }

    
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'buyer_id' => 'required|exists:buyers,id',
            'so_date' => 'required|date',
            'delivery_date' => 'nullable|date|after_or_equal:so_date',
            'customer_po_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'required|string|in:Draft,Confirmed',

            'details' => 'required|array|min:1',
            'details.*.item_id' => 'required|exists:items,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.unit_price' => 'required|numeric|min:0',
            'details.*.discount' => 'nullable|numeric|min:0',
            'details.*.specifications' => 'nullable|array',

            'subtotal' => 'required|numeric',
            'discount' => 'nullable|numeric',
            'tax_ppn' => 'nullable|numeric',
            'grand_total' => 'required|numeric',
            
            'currency' => 'required|string|in:IDR,USD',
            'exchange_rate' => 'required|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $soData = $request->only([
                'buyer_id', 'so_date', 'delivery_date', 'customer_po_number', 
                'notes', 'status', 'subtotal', 'discount', 'tax_ppn', 'grand_total',
                'currency', 'exchange_rate'
            ]);
            
            $soData['user_id'] = Auth::id();
            $soData['so_number'] = $this->generateSoNumber(); 

            $salesOrder = SalesOrder::create($soData);

            foreach ($request->details as $detail) {
                $item = Item::with('unit')->findOrFail($detail['item_id']);
                $lineTotal = ($detail['quantity'] * $detail['unit_price']) - ($detail['discount'] ?? 0);

                $salesOrder->details()->create([
                    'item_id' => $item->id,
                    'quantity' => $detail['quantity'],
                    'quantity_shipped' => 0,
                    'item_name' => $item->name, 
                    'item_unit' => $item->unit->name, 
                    'unit_price' => $detail['unit_price'],
                    'discount' => $detail['discount'] ?? 0,
                    'line_total' => $lineTotal,
                    'specifications' => $detail['specifications'] ?? null,
                ]);
            }
            
            DB::commit();

            $salesOrder->load(['buyer:id,name', 'user:id,name', 'details.item']);

            return response()->json([
                'success' => true,
                'message' => 'Sales Order berhasil disimpan!',
                'data' => $salesOrder
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal menyimpan Sales Order: ' . $e->getMessage());
            Log::error('Stack Trace: ' . $e->getTraceAsString()); 

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat menyimpan data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function show(string $id)
    {
        try {
            $salesOrder = SalesOrder::with(['buyer', 'user', 'details.item'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $salesOrder
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data Sales Order tidak ditemukan.'
            ], 404);
        }
    }

    
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'buyer_id' => 'required|exists:buyers,id',
            'so_date' => 'required|date',
            'delivery_date' => 'nullable|date|after_or_equal:so_date',
            'customer_po_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'required|string|in:Draft,Confirmed',

            'details' => 'required|array|min:1',
            'details.*.item_id' => 'required|exists:items,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.unit_price' => 'required|numeric|min:0',
            'details.*.discount' => 'nullable|numeric|min:0',
            'details.*.specifications' => 'nullable|array',

            'subtotal' => 'required|numeric',
            'discount' => 'nullable|numeric',
            'tax_ppn' => 'nullable|numeric',
            'grand_total' => 'required|numeric',
            
            'currency' => 'required|string|in:IDR,USD',
            'exchange_rate' => 'required|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $salesOrder = SalesOrder::findOrFail($id);

            $soData = $request->only([
                'buyer_id', 'so_date', 'delivery_date', 'customer_po_number', 
                'notes', 'status', 'subtotal', 'discount', 'tax_ppn', 'grand_total',
                'currency', 'exchange_rate'
            ]);
            
            $salesOrder->update($soData);

            $salesOrder->details()->delete();

            foreach ($request->details as $detail) {
                $item = Item::with('unit')->findOrFail($detail['item_id']);
                $lineTotal = ($detail['quantity'] * $detail['unit_price']) - ($detail['discount'] ?? 0);

                $salesOrder->details()->create([
                    'item_id' => $item->id,
                    'quantity' => $detail['quantity'],
                    'quantity_shipped' => 0,
                    'item_name' => $item->name, 
                    'item_unit' => $item->unit->name, 
                    'unit_price' => $detail['unit_price'],
                    'discount' => $detail['discount'] ?? 0,
                    'line_total' => $lineTotal,
                    'specifications' => $detail['specifications'] ?? null,
                ]);
            }
            
            DB::commit();

            $salesOrder->load(['buyer:id,name', 'user:id,name', 'details.item']);

            return response()->json([
                'success' => true,
                'message' => 'Sales Order berhasil diperbarui!',
                'data' => $salesOrder
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal memperbarui Sales Order: ' . $e->getMessage());
            Log::error('Stack Trace: ' . $e->getTraceAsString()); 

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat memperbarui data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function destroy(string $id)
    {
    }

    
    private function generateSoNumber()
    {
        $prefix = 'SO-' . date('Y') . '-' . date('m') . '-';
        $year = date('Y');
        $month = date('m');

        $lastSo = SalesOrder::whereYear('created_at', $year)
                            ->whereMonth('created_at', $month)
                            ->orderBy('id', 'desc')
                            ->first();
        
        $newNumber = 1;
        if ($lastSo) {
            $lastNumberStr = substr($lastSo->so_number, -4);
            if (is_numeric($lastNumberStr)) {
                $newNumber = (int)$lastNumberStr + 1;
            }
        }
        
        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}