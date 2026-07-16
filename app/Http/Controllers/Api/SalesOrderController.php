<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\Item;
use App\Models\Inventory;
use App\Models\Warehouse;
use App\Models\DeliveryOrderDetail;
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
                'buyer:id,name,address,eu_factory_number',
                'user:id,name',
                'details:id,sales_order_id,item_id,item_name,quantity,unit_price,line_total',
                'details.item:id,name,hs_code,nw_per_box,gw_per_box,m3_per_carton,wood_consumed_per_pcs'
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
            'delivery_date' => 'nullable|date',
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
            'tax_rate' => 'nullable|numeric|in:0,11,12',  // ← TAMBAH INI
            'grand_total' => 'required|numeric',

            'currency' => 'required|string|in:IDR,USD',
            'exchange_rate' => 'nullable|numeric',
            'shipment_date' => 'nullable|date',
            'payment_term' => 'nullable|string',
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
                'buyer_id',
                'so_date',
                'delivery_date',
                'shipment_date',
                'payment_term',
                'customer_po_number',
                'notes',
                'status',
                'subtotal',
                'discount',
                'tax_ppn',
                'tax_rate',
                'grand_total',
                'currency'
            ]);

            $soData['user_id'] = Auth::id();
            $soData['so_number'] = $this->generateSoNumber();
            $soData['exchange_rate'] = 1;

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

            $salesOrder->load(['buyer:id,name,address,eu_factory_number', 'user:id,name', 'details.item']);

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
            $query = SalesOrder::with([
                'buyer:id,name,address,eu_factory_number',
                'user:id,name',
                'details' => function ($q) {
                    $q->whereColumn('quantity', '>', 'quantity_shipped');
                },
                'details.item:id,name,code,stock,unit_id,hs_code,nw_per_box,gw_per_box,m3_per_carton,wood_consumed_per_pcs',
                'details.item.unit:id,name'
            ])
                ->select('id', 'so_number', 'buyer_id', 'user_id', 'so_date', 'grand_total', 'status', 'currency')
                ->where('status', '!=', 'Completed')
                ->where('status', '!=', 'Cancelled')
                ->whereHas('details', function ($q) {
                    $q->whereColumn('quantity', '>', 'quantity_shipped');
                })
                ->orderBy('so_date', 'desc')
                ->orderBy('id', 'desc');

            $salesOrders = $query->paginate($request->input('per_page', 25));

            $packingWarehouseId = Warehouse::where('code', 'PACKING')->value('id');

            $salesOrders->getCollection()->transform(function ($so) use ($packingWarehouseId) {
                $so->details->transform(function ($detail) use ($packingWarehouseId) {
                    $packingStock = (float) Inventory::where('item_id', $detail->item_id)
                        ->where('warehouse_id', $packingWarehouseId)
                        ->sum('qty_pcs');

                    // Fall back to items.stock when no inventory row exists in PACKING,
                    // mirroring the same logic used by the stock index report.
                    $detail->current_stock = $packingStock > 0
                        ? $packingStock
                        : (float) ($detail->item->stock ?? 0);

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
                'details' => function ($query) {
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
                'details.item:id,name,code,hs_code'
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
            'delivery_date' => 'nullable|date',
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
            'tax_rate' => 'nullable|numeric|in:0,11,12',  // ← TAMBAH INI
            'grand_total' => 'required|numeric',

            'currency' => 'required|string|in:IDR,USD',
            'exchange_rate' => 'nullable|numeric',
            'shipment_date' => 'nullable|date',
            'payment_term' => 'nullable|string',
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
                'buyer_id',
                'so_date',
                'delivery_date',
                'shipment_date',
                'payment_term',
                'customer_po_number',
                'notes',
                'status',
                'subtotal',
                'discount',
                'tax_ppn',
                'tax_rate',
                'grand_total',
                'currency'
            ]);

            $soData['exchange_rate'] = 1;

            $salesOrder->update($soData);

            // Ambil detail LAMA dulu sebelum dihapus — supaya quantity_shipped yang sudah
            // terkirim tidak hilang, dan referensi delivery_order_details.sales_order_detail_id
            // yang sudah dibuat sebelumnya (dari DO yang mereferensikan detail lama) bisa
            // di-repoint ke detail baru, bukan jadi basi/patah.
            $oldDetailsByItem = $salesOrder->details()->get()->groupBy('item_id');

            $salesOrder->details()->delete();

            foreach ($request->details as $detail) {
                $item = Item::with('unit')->findOrFail($detail['item_id']);
                $lineTotal = ($detail['quantity'] * $detail['unit_price']) - ($detail['discount'] ?? 0);

                // Cocokkan ke detail lama dengan item_id yang sama (kalau ada) — carry-over
                // quantity_shipped & repoint FK DO supaya tidak jadi basi.
                $oldMatch = null;
                if (!empty($oldDetailsByItem[$detail['item_id']])) {
                    $oldMatch = $oldDetailsByItem[$detail['item_id']]->shift();
                }

                $newDetail = $salesOrder->details()->create([
                    'item_id' => $item->id,
                    'quantity' => $detail['quantity'],
                    'quantity_shipped' => $oldMatch->quantity_shipped ?? 0,
                    'item_name' => $detail['item_name'] ?? $item->name,
                    'item_unit' => $item->unit->name,
                    'item_code' => $item->code ?? null,
                    'unit_price' => $detail['unit_price'],
                    'discount' => $detail['discount'] ?? 0,
                    'line_total' => $lineTotal,
                    'specifications' => $detail['specifications'] ?? null,
                    'delivery_date' => $detail['delivery_date'] ?? null,
                    'keterangan' => $detail['keterangan'] ?? null,
                ]);

                if ($oldMatch) {
                    DeliveryOrderDetail::where('sales_order_detail_id', $oldMatch->id)
                        ->update(['sales_order_detail_id' => $newDetail->id]);
                }
            }

            DB::commit();

            $salesOrder->load(['buyer:id,name,address,eu_factory_number', 'user:id,name', 'details.item']);

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
        $prefix = 'SO-' . date('Y') . '-';
        $year = date('Y');

        $lastSo = SalesOrder::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        $newNumber = 1;
        if ($lastSo) {
            $lastNumberStr = substr($lastSo->so_number, -4);
            if (is_numeric($lastNumberStr)) {
                $newNumber = (int) $lastNumberStr + 1;
            }
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    public function generatePINumber(string $id)
    {
        DB::beginTransaction();
        try {
            $salesOrder = SalesOrder::lockForUpdate()->findOrFail($id);

            if ($salesOrder->no_pi) {
                return response()->json([
                    'success' => true,
                    'no_pi' => $salesOrder->no_pi,
                ]);
            }

            $year = date('Y');

            $last = SalesOrder::whereNotNull('no_pi')
                ->where('no_pi', 'LIKE', "PI/{$year}/%")
                ->orderByRaw('CAST(SUBSTRING(no_pi, 9) AS UNSIGNED) DESC')
                ->first();

            $newNumber = 1;
            if ($last) {
                $lastSeq = (int) substr($last->no_pi, strrpos($last->no_pi, '/') + 1);
                $newNumber = $lastSeq + 1;
            }

            $noPi = 'PI/' . $year . '/' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);

            $salesOrder->update(['no_pi' => $noPi]);

            DB::commit();

            return response()->json([
                'success' => true,
                'no_pi' => $noPi,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal generate PI number: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal generate nomor PI: ' . $e->getMessage(),
            ], 500);
        }
    }
}
