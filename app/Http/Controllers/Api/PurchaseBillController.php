<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseBill;
use App\Models\GoodsReceiptDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PurchaseBillController extends Controller
{
    
    public function store(Request $request)
    {
        
        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,id',
            'supplier_invoice_number' => 'required|string|max:255',
            'bill_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:bill_date',
            'notes' => 'nullable|string',
            'ppn_percentage' => 'nullable|numeric|min:0|max:100', 
            'details' => 'required|array|min:1',
            'details.*.goods_receipt_detail_id' => 'required|exists:goods_receipt_details,id',
            'details.*.item_id' => 'required|exists:items,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.price' => 'required|numeric|min:0',
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
            
            
            $subtotal = 0;
            foreach($validatedData['details'] as $detail) {
                $subtotal += $detail['quantity'] * $detail['price'];
            }
            
            
            $ppnPercentage = $validatedData['ppn_percentage'] ?? 0;
            $ppnAmount = $subtotal * ($ppnPercentage / 100); 
            $totalAmount = $subtotal + $ppnAmount;
        

            
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
                'notes' => $validatedData['notes'],
            ]);

            
            foreach ($validatedData['details'] as $detail) {
                
            
                $goodsReceiptDetail = GoodsReceiptDetail::with('purchaseOrderDetail')
                    ->find($detail['goods_receipt_detail_id']);

                
                $specifications = null;
                if ($goodsReceiptDetail && $goodsReceiptDetail->purchaseOrderDetail) {
                    $specifications = $goodsReceiptDetail->purchaseOrderDetail->specifications;
                }

                
                $purchaseBill->details()->create([
                    'goods_receipt_detail_id' => $detail['goods_receipt_detail_id'],
                    'item_id' => $detail['item_id'],
                    'quantity' => $detail['quantity'],
                    'price' => $detail['price'],
                    'subtotal' => $detail['quantity'] * $detail['price'],
                    'specifications' => $specifications, 
                ]);

                
                if ($goodsReceiptDetail) {
                    $goodsReceiptDetail->update(['billed' => true]);
                }
            }

        
            DB::commit();

            return response()->json([
                'success' => true, 
                'message' => 'Faktur Pembelian berhasil disimpan.',
                'data' => $purchaseBill->load(['supplier', 'details.item'])
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
        $bills = PurchaseBill::with('supplier:id,name')
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
                'details.goodsReceiptDetail.purchaseOrderDetail'
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
        
        // Return: BILL-202510-0001
        return $prefix . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}
