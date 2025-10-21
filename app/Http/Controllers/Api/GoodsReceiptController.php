<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GoodsReceiptController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'receipt_date' => 'required|date',
            'supplier_document_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'details' => 'required|array|min:1',
            'details.*.item_id' => 'required|exists:items,id',
            'details.*.quantity_received' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();
            $purchaseOrder = PurchaseOrder::with('details')->findOrFail($validatedData['purchase_order_id']);

            $goodsReceipt = GoodsReceipt::create([
                'receipt_number' => $this->generateReceiptNumber(),
                'purchase_order_id' => $purchaseOrder->id,
                'receipt_date' => $validatedData['receipt_date'],
                'supplier_document_number' => $validatedData['supplier_document_number'],
                'notes' => $validatedData['notes'],
            ]);

            foreach ($validatedData['details'] as $detail) {
                if ($detail['quantity_received'] > 0) {
                    $poDetail = $purchaseOrder->details->firstWhere('item_id', $detail['item_id']);

                    $goodsReceipt->details()->create([
                        'purchase_order_detail_id' => $poDetail->id ?? null,
                        'item_id' => $detail['item_id'],
                        'quantity_ordered' => $poDetail->quantity_ordered ?? 0,
                        'quantity_received' => $detail['quantity_received'],
                    ]);

                    StockMovement::create([
                        'item_id' => $detail['item_id'],
                        'type' => 'Pembelian',
                        'quantity' => $detail['quantity_received'],
                        'notes' => 'Penerimaan dari PO #' . $purchaseOrder->po_number,
                    ]);

                    $item = \App\Models\Item::find($detail['item_id']);
                    if ($item) {
                        $item->increment('stock', $detail['quantity_received']);
                    }
                }
            }

            $this->updatePurchaseOrderStatus($purchaseOrder);
            
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Penerimaan barang berhasil dicatat dan stok telah diperbarui.',
                'data' => $goodsReceipt->load('details.item')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false, 
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getUnbilledReceipts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $receipts = \App\Models\GoodsReceipt::where('status', '!=', 'Open')
            ->whereHas('purchaseOrder', function ($query) use ($request) {
                $query->where('supplier_id', $request->supplier_id);
            })
            ->whereDoesntHave('details.purchaseBillDetail')
            ->with([
                'purchaseOrder.details', 
                'details.item:id,name,code',
                'details.purchaseOrderDetail',
            ])
            ->get();

        foreach ($receipts as $receipt) {
            if ($receipt->purchaseOrder && $receipt->purchaseOrder->details) {
                foreach ($receipt->details as $grDetail) {
                    $poDetail = $grDetail->purchaseOrderDetail;
                    
                    if (!$poDetail) {
                        $poDetail = $receipt->purchaseOrder->details->firstWhere('item_id', $grDetail->item_id);
                    }

                    if ($poDetail) {
                        $grDetail->price = $poDetail->price;
                        $grDetail->specifications = $poDetail->specifications;
                        $grDetail->purchaseOrderDetail = $poDetail;
                    } else {
                        $grDetail->price = 0;
                        $grDetail->specifications = null;
                    }
                }
            }
        }

        return response()->json(['success' => true, 'data' => $receipts]);
    }

    private function updatePurchaseOrderStatus(PurchaseOrder $purchaseOrder)
    {
        $totalOrdered = $purchaseOrder->details()->sum('quantity_ordered');
        $totalReceived = $purchaseOrder->receipts()->with('details')->get()->flatMap(function ($receipt) {
            return $receipt->details;
        })->sum('quantity_received');

        if ($totalReceived >= $totalOrdered) {
            $purchaseOrder->status = 'Selesai';
        } else if ($totalReceived > 0) {
            $purchaseOrder->status = 'Diterima Sebagian';
        } else {
            $purchaseOrder->status = 'Open';
        }

        $purchaseOrder->save();
    }

    private function generateReceiptNumber()
    {
        $prefix = 'GR-' . now()->format('Ym');
        $lastReceipt = GoodsReceipt::where('receipt_number', 'like', $prefix . '%')->latest('id')->first();
        $number = 1;
        if ($lastReceipt) {
            $number = (int) substr($lastReceipt->receipt_number', -4) + 1;
        }
        return $prefix . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}
