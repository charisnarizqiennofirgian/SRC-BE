<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\Item;
use App\Models\Inventory;
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
            $query = SalesOrder::with([
                    'buyer:id,name',
                    'user:id,name',
                    'details:id,sales_order_id,item_id,item_name,quantity,unit_price,line_total',
                    'details.item:id,name'
                ])
                ->select('id', 'so_number', 'buyer_id', 'user_id', 'so_date', 'grand_total', 'status', 'currency');

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
            'customer_po_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'required|string|in:Draft,Confirmed',

            'details' => 'required|array|min:1',
            'details.*.item_id' => 'required|exists:items,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.unit_price' => 'required|numeric|min:0',
            'details.*.discount' => 'nullable|numeric|min:0',
            'details.*.specifications' => 'nullable|array',
            'details.*.delivery_date' => 'nullable|date',
            'details.*.keterangan' => 'nullable|string',

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
                'buyer_id', 'so_date', 'customer_po_number',
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
                    'item_code' => $item->code ?? null,
                    'unit_price' => $detail['unit_price'],
                    'discount' => $detail['discount'] ?? 0,
                    'line_total' => $lineTotal,
                    'specifications' => $detail['specifications'] ?? null,
                    'delivery_date' => $detail['delivery_date'] ?? null,
                    'keterangan' => $detail['keterangan'] ?? null,
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

    public function getOpenSalesOrders(Request $request)
    {
        try {
            $query = SalesOrder::with(['buyer:id,name', 'user:id,name', 'details' => function($q){
                $q->whereColumn('quantity', '>', 'quantity_shipped');
            }, 'details.item:id,name,code,stock,unit_id', 'details.item.unit:id,name'])
            ->select('id', 'so_number', 'buyer_id', 'user_id', 'so_date', 'grand_total', 'status', 'currency')
            ->where('status', '!=', 'Completed')
            ->where('status', '!=', 'Cancelled')
            ->whereHas('details', function($q){
                $q->whereColumn('quantity', '>', 'quantity_shipped');
            })
            ->orderBy('so_date', 'desc')
            ->orderBy('id', 'desc');

            $salesOrders = $query->paginate($request->input('per_page', 25));

            // Ambil stok dari Gudang Packing untuk setiap item
            $packingWarehouseId = 11; // Gudang Packing

            $salesOrders->getCollection()->transform(function ($so) use ($packingWarehouseId) {
                $so->details->transform(function ($detail) use ($packingWarehouseId) {
                    // Ambil stok dari tabel inventories di Gudang Packing
                    $inventory = Inventory::where('item_id', $detail->item_id)
                        ->where('warehouse_id', $packingWarehouseId)
                        ->first();

                    // Tambahkan current_stock ke detail
                    $detail->current_stock = $inventory ? (float) $inventory->qty : 0;

                    return $detail;
                });
                return $so;
            });

            return response()->json([
                'success' => true,
                'data' => $salesOrders
            ], 200);

        } catch (\Exception $e) {
            Log::error('Gagal mengambil daftar Sales Order terbuka: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data.'
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $salesOrder = SalesOrder::with([
                'buyer',
                'user',
                'details' => function($query) {
                    $query->select(
                        'id',
                        'sales_order_id',
                        'item_id',
                        'item_name',
                        'item_unit',
                        'item_code',
                        'quantity',
                        'unit_price',
                        'discount',
                        'line_total',
                        'delivery_date',
                        'keterangan'
                    );
                },
                'details.item:id,name,code'
            ])->findOrFail($id);

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
            'customer_po_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'required|string|in:Draft,Confirmed',

            'details' => 'required|array|min:1',
            'details.*.item_id' => 'required|exists:items,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.unit_price' => 'required|numeric|min:0',
            'details.*.discount' => 'nullable|numeric|min:0',
            'details.*.specifications' => 'nullable|array',
            'details.*.delivery_date' => 'nullable|date',
            'details.*.keterangan' => 'nullable|string',

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
                'buyer_id', 'so_date', 'customer_po_number',
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
                    'item_code' => $item->code ?? null,
                    'unit_price' => $detail['unit_price'],
                    'discount' => $detail['discount'] ?? 0,
                    'line_total' => $lineTotal,
                    'specifications' => $detail['specifications'] ?? null,
                    'delivery_date' => $detail['delivery_date'] ?? null,
                    'keterangan' => $detail['keterangan'] ?? null,
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
        try {
            $salesOrder = SalesOrder::findOrFail($id);
            $salesOrder->delete();

            return response()->json([
                'success' => true,
                'message' => 'Sales Order berhasil dihapus.'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Gagal menghapus Sales Order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus Sales Order.'
            ], 500);
        }
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
