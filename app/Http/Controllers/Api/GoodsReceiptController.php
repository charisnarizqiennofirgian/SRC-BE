<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\InventoryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\Item;

class GoodsReceiptController extends Controller
{
    // GET /goods-receipts?purchase_order_id=X — daftar GR per PO
    public function index(Request $request)
    {
        $query = GoodsReceipt::with([
            'details.item:id,name,code',
            'details.purchaseBillDetail:id,goods_receipt_detail_id,purchase_bill_id',
        ]);

        if ($request->filled('purchase_order_id')) {
            $query->where('purchase_order_id', $request->purchase_order_id);
        }

        $receipts = $query->latest()->get()->map(fn($gr) => [
            'id'                       => $gr->id,
            'receipt_number'           => $gr->receipt_number,
            'receipt_date'             => $gr->receipt_date ? \Carbon\Carbon::parse($gr->receipt_date)->toDateString() : null,
            'supplier_document_number' => $gr->supplier_document_number,
            'notes'                    => $gr->notes,
            'is_billed'                => $gr->details->some(fn($d) => $d->purchaseBillDetail !== null),
            'details'                  => $gr->details->map(fn($d) => [
                'id'                => $d->id,
                'item_name'         => $d->item?->name ?? '-',
                'item_code'         => $d->item?->code ?? '-',
                'quantity_received' => $d->quantity_received,
                'price'             => $d->price,
                'subtotal'          => $d->subtotal,
                'is_billed'         => $d->purchaseBillDetail !== null,
            ]),
        ]);

        return response()->json(['success' => true, 'data' => $receipts]);
    }

    // DELETE /goods-receipts/{id} — batalkan GR (reverse stok)
    public function destroy($id)
    {
        $gr = GoodsReceipt::with([
            'details.item',
            'details.purchaseBillDetail',
            'purchaseOrder',
        ])->findOrFail($id);

        // Cek apakah sudah ada faktur dari GR ini
        foreach ($gr->details as $detail) {
            if ($detail->purchaseBillDetail !== null) {
                return response()->json([
                    'success' => false,
                    'message' => "Tidak bisa dibatalkan — penerimaan ini sudah dibuatkan faktur pembelian. Batalkan fakturnya terlebih dahulu.",
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $bufferWarehouse = Warehouse::where('code', 'BUFFER')->first();

            foreach ($gr->details as $detail) {
                $warehouseId = $bufferWarehouse->id ?? 1;

                // Reverse inventories table per grade
                \App\Models\Inventory::decrementGradeStock(
                    itemId: $detail->item_id,
                    warehouseId: $warehouseId,
                    qty: $detail->quantity_received,
                    grade: $detail->grade,
                );

                // Reverse stok global item
                if ($detail->item) {
                    $detail->item->decrement('stock', $detail->quantity_received);
                }

                InventoryLog::create([
                    'date'             => now()->toDateString(),
                    'time'             => now()->toTimeString(),
                    'item_id'          => $detail->item_id,
                    'warehouse_id'     => $warehouseId,
                    'qty'              => $detail->quantity_received,
                    'direction'        => 'OUT',
                    'transaction_type' => 'PURCHASE_REVERSAL',
                    'reference_type'   => 'GoodsReceipt',
                    'reference_id'     => $gr->id,
                    'reference_number' => $gr->receipt_number,
                    'notes'            => 'Pembatalan penerimaan ' . $gr->receipt_number . ($detail->grade ? " (Grade {$detail->grade})" : ''),
                    'grade'            => $detail->grade,
                    'user_id'          => Auth::id(),
                ]);
            }

            // Hard-delete detail lalu soft-delete GR header
            $gr->details()->delete();
            $gr->delete();

            // Recalculate status PO
            $po     = $gr->purchaseOrder;
            $isKayu = $po->type === 'kayu';
            $this->updatePurchaseOrderStatus($po, $isKayu);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Penerimaan {$gr->receipt_number} berhasil dibatalkan dan stok telah dikembalikan.",
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'purchase_order_id'                  => 'required|exists:purchase_orders,id',
            'receipt_date'                        => 'required|date',
            'supplier_document_number'            => 'nullable|string|max:255',
            'notes'                               => 'nullable|string',
            'details'                             => 'required|array|min:1',
            'details.*.item_id'                   => 'required|exists:items,id',
            'details.*.quantity_received'         => 'required|numeric|min:0',
            'details.*.price'                     => 'nullable|numeric|min:0',
            'details.*.grade'                     => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();
            $purchaseOrder = PurchaseOrder::with('details')->findOrFail($validatedData['purchase_order_id']);
            $isKayu        = $purchaseOrder->type === 'kayu';

            $goodsReceipt = GoodsReceipt::create([
                'receipt_number'           => $this->generateReceiptNumber(),
                'purchase_order_id'        => $purchaseOrder->id,
                'receipt_date'             => $validatedData['receipt_date'],
                'supplier_document_number' => $validatedData['supplier_document_number'] ?? null,
                'notes'                    => $validatedData['notes'] ?? null,
            ]);

            $bufferWarehouse = Warehouse::where('code', 'BUFFER')->first();

            foreach ($validatedData['details'] as $detail) {
                if ($detail['quantity_received'] > 0) {
                    $poDetail = $purchaseOrder->details->firstWhere('item_id', $detail['item_id']);
                    $price    = isset($detail['price']) ? (float) $detail['price'] : null;
                    $subtotal = ($price !== null) ? $detail['quantity_received'] * $price : null;
                    $grade    = $detail['grade'] ?? null;

                    $goodsReceipt->details()->create([
                        'purchase_order_detail_id' => $poDetail->id ?? null,
                        'item_id'                  => $detail['item_id'],
                        'quantity_ordered'          => $poDetail->quantity_ordered ?? 0,
                        'quantity_received'         => $detail['quantity_received'],
                        'price'                    => $price,
                        'subtotal'                 => $subtotal,
                        'grade'                    => $grade,
                    ]);

                    StockMovement::create([
                        'item_id'  => $detail['item_id'],
                        'type'     => 'Pembelian',
                        'quantity' => $detail['quantity_received'],
                        'notes'    => 'Penerimaan dari PO #' . $purchaseOrder->po_number,
                    ]);

                    $warehouseId = $bufferWarehouse->id ?? 1;

                    InventoryLog::create([
                        'date'             => $validatedData['receipt_date'],
                        'time'             => now()->toTimeString(),
                        'item_id'          => $detail['item_id'],
                        'warehouse_id'     => $warehouseId,
                        'qty'              => $detail['quantity_received'],
                        'direction'        => 'IN',
                        'transaction_type' => 'PURCHASE',
                        'reference_type'   => 'GoodsReceipt',
                        'reference_id'     => $goodsReceipt->id,
                        'reference_number' => $goodsReceipt->receipt_number,
                        'notes'            => 'Penerimaan dari PO #' . $purchaseOrder->po_number . ($grade ? " (Grade {$grade})" : ''),
                        'grade'            => $grade,
                        'user_id'          => Auth::id(),
                    ]);

                    // Update inventories table per (item, warehouse, grade)
                    \App\Models\Inventory::incrementGlobalStock(
                        warehouseId: $warehouseId,
                        itemId: $detail['item_id'],
                        qty: $detail['quantity_received'],
                        grade: $grade,
                    );

                    $item = Item::find($detail['item_id']);
                    if ($item) {
                        $item->increment('stock', $detail['quantity_received']);
                    }
                }
            }

            // PO Kayu: status otomatis hanya Open → Diterima Sebagian; tutup manual via /tutup
            // PO lain: tetap auto-close saat total diterima ≥ ordered
            $this->updatePurchaseOrderStatus($purchaseOrder, $isKayu);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Penerimaan barang berhasil dicatat dan stok telah diperbarui.',
                'data'    => $goodsReceipt->load('details.item'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage(),
            ], 500);
        }
    }

    // POST /goods-receipts/{po_id}/tutup — tutup PO Kayu secara manual
    public function tutup($poId)
    {
        $purchaseOrder = PurchaseOrder::findOrFail($poId);

        if ($purchaseOrder->type !== 'kayu') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya PO Kayu yang bisa ditutup manual.',
            ], 422);
        }

        if ($purchaseOrder->status === 'Selesai') {
            return response()->json([
                'success' => false,
                'message' => 'PO sudah berstatus Selesai.',
            ], 422);
        }

        $purchaseOrder->update(['status' => 'Selesai']);

        return response()->json([
            'success' => true,
            'message' => "PO {$purchaseOrder->po_number} berhasil ditutup.",
        ]);
    }

    public function getUnbilledReceipts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $receipts = GoodsReceipt::whereIn('status', ['Open', 'Partial'])
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
                    // Kalau GR detail punya price sendiri (kayu RST), pakai itu.
                    // Kalau tidak, fallback ke harga PO (perilaku lama, data lama tetap aman).
                    if ($grDetail->price !== null) {
                        continue;
                    }

                    $poDetail = $grDetail->purchaseOrderDetail;
                    if (!$poDetail) {
                        $poDetail = $receipt->purchaseOrder->details->firstWhere('item_id', $grDetail->item_id);
                    }

                    if ($poDetail) {
                        $grDetail->price            = $poDetail->price;
                        $grDetail->specifications   = $poDetail->specifications;
                        $grDetail->purchaseOrderDetail = $poDetail;
                    } else {
                        $grDetail->price          = 0;
                        $grDetail->specifications = null;
                    }
                }
            }
        }

        return response()->json(['success' => true, 'data' => $receipts]);
    }

    private function updatePurchaseOrderStatus(PurchaseOrder $purchaseOrder, bool $isKayu = false)
    {
        $totalOrdered  = $purchaseOrder->details()->sum('quantity_ordered');
        $totalReceived = $purchaseOrder->receipts()->with('details')->get()
            ->flatMap(fn($r) => $r->details)
            ->sum('quantity_received');

        if ($isKayu) {
            // Kayu: tidak auto-Selesai; tutup manual via endpoint /tutup
            $purchaseOrder->status = $totalReceived > 0 ? 'Diterima Sebagian' : 'Open';
        } else {
            if ($totalReceived >= $totalOrdered) {
                $purchaseOrder->status = 'Selesai';
            } elseif ($totalReceived > 0) {
                $purchaseOrder->status = 'Diterima Sebagian';
            } else {
                $purchaseOrder->status = 'Open';
            }
        }

        $purchaseOrder->save();
    }

    private function generateReceiptNumber()
    {
        $prefix      = 'GR-' . now()->format('Ym');
        $lastReceipt = GoodsReceipt::withTrashed()
            ->where('receipt_number', 'like', $prefix . '%')
            ->latest('id')
            ->first();
        $number = 1;
        if ($lastReceipt) {
            $number = (int) substr($lastReceipt->receipt_number, -4) + 1;
        }
        return $prefix . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}
