<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Info(
 *     title="ERP Kayu API",
 *     version="1.0.0",
 *     description="Dokumentasi API untuk sistem ERP pengolahan kayu",
 *     @OA\Contact(
 *         email="support@erp-kayu.com"
 *     ),
 *     @OA\License(
 *         name="Apache 2.0",
 *         url="https://www.apache.org/licenses/LICENSE-2.0.html"
 *     )
 * )
 * @OA\Server(
 *     url=L5_SWAGGER_CONST_HOST,
 *     description="Server Development"
 * )
 * @OA\Tag(
 *     name="Pembelian",
 *     description="Manajemen pembelian kayu dari supplier"
 * )
 * @OA\SecurityScheme(
 *     securityScheme="sanctum",
 *     type="apiKey",
 *     in="header",
 *     name="Authorization",
 *     description="Masukkan token dalam format: Bearer {token}"
 * )
 */
class GoodsReceiptController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/goods-receipts",
     *     summary="Simpan penerimaan barang dari supplier",
     *     tags={"Pembelian"},
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Data penerimaan barang",
     *         @OA\JsonContent(
     *             required={"purchase_order_id","receipt_date","details"},
     *             @OA\Property(property="purchase_order_id", type="integer", example=2, description="ID Purchase Order"),
     *             @OA\Property(property="receipt_date", type="string", format="date", example="2025-12-01", description="Tanggal penerimaan"),
     *             @OA\Property(property="supplier_document_number", type="string", example="INV-67657", description="Nomor dokumen dari supplier"),
     *             @OA\Property(property="notes", type="string", example="Kayu jati grade A", description="Catatan tambahan"),
     *             @OA\Property(property="details", type="array", description="Daftar item yang diterima",
     *                 @OA\Items(
     *                     @OA\Property(property="item_id", type="integer", example=5, description="ID Item"),
     *                     @OA\Property(property="quantity_received", type="number", format="float", example=10.5, description="Jumlah diterima (m3)")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Sukses menyimpan data",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Penerimaan barang berhasil dicatat dan stok telah diperbarui."),
     *             @OA\Property(property="data", type="object", description="Data penerimaan barang yang disimpan")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validasi gagal",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="errors", type="object", description="Daftar error validasi")
     *         )
     *     )
     * )
     */
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

        // ✅ PERBAIKAN: Ganti "where('status', '!=', 'Open')" menjadi "whereIn('status', ['Open', 'Partial'])"
        // Alasan: 
        // 1. Kolom 'status' sudah ada di database (dari migration sebelumnya)
        // 2. Logika bisnis: Faktur bisa dibuat untuk surat jalan yang statusnya "Open" (belum pernah difaktur) atau "Partial" (sebagian sudah difaktur)
        $receipts = \App\Models\GoodsReceipt::whereIn('status', ['Open', 'Partial'])
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
             $number = (int) substr($lastReceipt->receipt_number, -4) + 1;
        }
        return $prefix . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}