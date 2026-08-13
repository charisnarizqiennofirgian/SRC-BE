<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['supplier', 'details.item:id,name'])
            ->orderByRaw("CASE WHEN status = 'Open' THEN 0 ELSE 1 END")
            ->latest();

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('source_type')) {
            $query->where('source_type', $request->source_type);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', '%' . $search . '%')
                  ->orWhereHas('supplier', function ($sq) use ($search) {
                      $sq->where('name', 'like', '%' . $search . '%');
                  });
            });
        }

        $perPage = $request->get('per_page', 15);
        $orders = $query->paginate($perPage);
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

            $currency     = $validatedData['currency'] ?? 'IDR';
            $exchangeRate = (float) ($validatedData['exchange_rate'] ?? 1);
            $otherCost    = (float) ($validatedData['other_cost'] ?? 0);
            $totals = $this->calculateTotals($validatedData['details'], $validatedData['ppn_percentage'], $exchangeRate, $otherCost);

            $order = PurchaseOrder::create([
                'created_by'      => Auth::id(),
                'po_number'       => $this->generatePoNumber(),
                'supplier_id'     => $validatedData['supplier_id'],
                'order_date'      => $validatedData['order_date'],
                'delivery_date'   => $validatedData['delivery_date'] ?? null,
                'no_surat_jalan'  => $validatedData['no_surat_jalan'] ?? null,
                'status'          => 'Open',
                'notes'           => $validatedData['notes'] ?? null,
                'type'            => $validatedData['type'],
                'source_type'     => 'direct',
                'currency'        => $currency,
                'exchange_rate'   => $exchangeRate,
                'subtotal'        => $totals['subtotal'],
                'ppn_percentage'  => $totals['ppn_percentage'],
                'ppn_amount'      => $totals['ppn_amount'],
                'other_cost'      => $totals['other_cost'],
                'other_cost_description' => $validatedData['other_cost_description'] ?? null,
                'grand_total'     => $totals['grand_total'],
            ]);

            $order->details()->createMany($this->prepareDetails($validatedData['details'], $exchangeRate));

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
        $purchaseOrder->load('supplier', 'details.item.unit', 'receipts.details');

        $receivedPerDetail = [];
        foreach ($purchaseOrder->receipts as $receipt) {
            foreach ($receipt->details as $rd) {
                $key = $rd->purchase_order_detail_id;
                $receivedPerDetail[$key] = ($receivedPerDetail[$key] ?? 0) + $rd->quantity_received;
            }
        }

        $purchaseOrder->details->each(function ($detail) use ($receivedPerDetail) {
            if ($detail->specifications) {
                $detail->specifications = is_string($detail->specifications)
                    ? json_decode($detail->specifications, true)
                    : $detail->specifications;
            }
            $received = $receivedPerDetail[$detail->id] ?? 0;
            $detail->quantity_received_total = (float) $received;
            $detail->quantity_remaining      = (float) max(0, $detail->quantity_ordered - $received);
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

            $currency     = $validatedData['currency'] ?? 'IDR';
            $exchangeRate = (float) ($validatedData['exchange_rate'] ?? 1);
            $otherCost    = (float) ($validatedData['other_cost'] ?? 0);
            $totals = $this->calculateTotals($validatedData['details'], $validatedData['ppn_percentage'], $exchangeRate, $otherCost);

            $purchaseOrder->update([
                'supplier_id'   => $validatedData['supplier_id'],
                'order_date'    => $validatedData['order_date'],
                'delivery_date' => $validatedData['delivery_date'] ?? null,
                'notes'         => $validatedData['notes'] ?? null,
                'type'          => $validatedData['type'],
                'currency'      => $currency,
                'exchange_rate' => $exchangeRate,
                'subtotal'      => $totals['subtotal'],
                'ppn_percentage'=> $totals['ppn_percentage'],
                'ppn_amount'    => $totals['ppn_amount'],
                'other_cost'    => $totals['other_cost'],
                'other_cost_description' => $validatedData['other_cost_description'] ?? null,
                'grand_total'   => $totals['grand_total'],
            ]);

            $purchaseOrder->details()->delete();
            $purchaseOrder->details()->createMany($this->prepareDetails($validatedData['details'], $exchangeRate));

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


    public function updatePrice(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->type !== 'operasional') {
            return response()->json(['success' => false, 'message' => 'Fitur edit harga ini hanya untuk PO Operasional.'], 400);
        }

        if ($purchaseOrder->status !== 'Diterima Sebagian') {
            return response()->json(['success' => false, 'message' => 'Fitur ini hanya untuk PO dengan status Diterima Sebagian.'], 400);
        }

        $validator = Validator::make($request->all(), [
            'details'          => 'required|array|min:1',
            'details.*.id'     => 'required|integer|exists:purchase_order_details,id',
            'details.*.price'  => 'required|numeric|min:0',
            'other_cost'             => 'nullable|numeric|min:0',
            'other_cost_description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $lockedItems = [];

            foreach ($validator->validated()['details'] as $row) {
                $detail = $purchaseOrder->details()->find($row['id']);
                if (!$detail) {
                    continue;
                }


                $alreadyBilled = DB::table('goods_receipt_details')
                    ->where('purchase_order_detail_id', $detail->id)
                    ->whereExists(function ($q) {
                        $q->select('id')
                            ->from('purchase_bill_details')
                            ->whereColumn('purchase_bill_details.goods_receipt_detail_id', 'goods_receipt_details.id');
                    })
                    ->exists();

                if ($alreadyBilled) {
                    $lockedItems[] = [
                        'item_id'   => $detail->item_id,
                        'item_name' => optional($detail->item)->name,
                    ];
                    continue;
                }

                $newPrice = (float) $row['price'];
                $detail->update([
                    'price'    => $newPrice,
                    'subtotal' => $detail->quantity_ordered * $newPrice * $purchaseOrder->exchange_rate,
                ]);
            }

            $freshDetails = $purchaseOrder->details()->get(['quantity_ordered', 'price'])
                ->map(fn ($d) => ['quantity' => $d->quantity_ordered, 'price' => $d->price])
                ->all();

            // Ongkir/biaya lain-lain seringkali baru diketahui SETELAH barang dikirim/diterima
            // sebagian — boleh diisi/diedit di sini juga, bukan cuma harga per item.
            $otherCost = $request->filled('other_cost')
                ? (float) $request->other_cost
                : (float) $purchaseOrder->other_cost;
            $otherCostDescription = $request->has('other_cost_description')
                ? $request->other_cost_description
                : $purchaseOrder->other_cost_description;

            $totals = $this->calculateTotals(
                $freshDetails,
                (float) $purchaseOrder->ppn_percentage,
                (float) $purchaseOrder->exchange_rate,
                $otherCost
            );

            $purchaseOrder->update([
                'subtotal'    => $totals['subtotal'],
                'ppn_amount'  => $totals['ppn_amount'],
                'other_cost'  => $totals['other_cost'],
                'other_cost_description' => $otherCostDescription,
                'grand_total' => $totals['grand_total'],
            ]);

            DB::commit();

            return response()->json([
                'success'      => true,
                'message'      => 'Harga PO berhasil diupdate.',
                'data'         => $purchaseOrder->load('supplier', 'details.item'),
                'locked_items' => $lockedItems,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
        }
    }


    public function updateQuantity(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->type !== 'kayu') {
            return response()->json(['success' => false, 'message' => 'Fitur edit jumlah ini hanya untuk PO Kayu.'], 400);
        }

        if ($purchaseOrder->status !== 'Diterima Sebagian') {
            return response()->json(['success' => false, 'message' => 'Fitur ini hanya untuk PO dengan status Diterima Sebagian.'], 400);
        }

        $validator = Validator::make($request->all(), [
            'details'            => 'required|array|min:1',
            'details.*.id'       => 'required|integer|exists:purchase_order_details,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            foreach ($validator->validated()['details'] as $row) {
                $detail = $purchaseOrder->details()->find($row['id']);
                if (!$detail) {
                    continue;
                }

                $newQuantity = (float) $row['quantity'];
                $detail->update([
                    'quantity_ordered' => $newQuantity,
                    'subtotal'         => $newQuantity * $detail->price * $purchaseOrder->exchange_rate,
                ]);
            }

            $freshDetails = $purchaseOrder->details()->get(['quantity_ordered', 'price'])
                ->map(fn ($d) => ['quantity' => $d->quantity_ordered, 'price' => $d->price])
                ->all();

            $totals = $this->calculateTotals(
                $freshDetails,
                (float) $purchaseOrder->ppn_percentage,
                (float) $purchaseOrder->exchange_rate,
                (float) $purchaseOrder->other_cost
            );

            $purchaseOrder->update([
                'subtotal'    => $totals['subtotal'],
                'ppn_amount'  => $totals['ppn_amount'],
                'grand_total' => $totals['grand_total'],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Jumlah pesanan PO berhasil diupdate.',
                'data'    => $purchaseOrder->load('supplier', 'details.item'),
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

    public function laporanHarga(Request $request)
    {
        $query = DB::table('purchase_order_details as pod')
            ->join('purchase_orders as po', 'pod.purchase_order_id', '=', 'po.id')
            ->join('items as i', 'pod.item_id', '=', 'i.id')
            ->join('suppliers as s', 'po.supplier_id', '=', 's.id')
            ->select(
                'pod.id',
                'i.id as item_id',
                'i.name as item_name',
                'i.code as item_code',
                's.id as supplier_id',
                's.name as supplier_name',
                'po.po_number',
                'po.order_date',
                'pod.price',
                'pod.quantity_ordered',
            )
            ->where('po.status', '!=', 'Cancelled');

        // Filter item
        if ($request->filled('item_id')) {
            $query->where('pod.item_id', $request->item_id);
        }

        // Filter supplier
        if ($request->filled('supplier_id')) {
            $query->where('po.supplier_id', $request->supplier_id);
        }

        // Filter tanggal
        if ($request->filled('date_from')) {
            $query->where('po.order_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('po.order_date', '<=', $request->date_to);
        }

        $data = $query->orderBy('i.name')->orderBy('po.order_date')->get();

        // Hitung perubahan harga per item
        $grouped = $data->groupBy('item_id')->map(function ($rows) {
            $rows = $rows->values();
            return $rows->map(function ($row, $index) use ($rows) {
                $prevPrice = $index > 0 ? $rows[$index - 1]->price : null;
                $row->price_change = null;
                $row->price_change_percent = null;
                $row->price_trend = null;

                if ($prevPrice !== null && $prevPrice > 0) {
                    $change = $row->price - $prevPrice;
                    $row->price_change = $change;
                    $row->price_change_percent = round(($change / $prevPrice) * 100, 2);
                    $row->price_trend = $change > 0 ? 'naik' : ($change < 0 ? 'turun' : 'tetap');
                }

                return $row;
            });
        })->values()->flatten(1);

        return response()->json([
            'success' => true,
            'data' => $data,
            'chart_data' => $this->prepareChartData($data),
        ]);
    }

    private function prepareChartData($data)
    {
        return $data->groupBy('item_id')->map(function ($rows, $itemId) {
            return [
                'item_id' => $itemId,
                'item_name' => $rows->first()->item_name,
                'labels' => $rows->pluck('order_date'),
                'prices' => $rows->pluck('price'),
            ];
        })->values();
    }

    private function validatePurchaseOrder(Request $request)
    {
        return Validator::make($request->all(), [
            'supplier_id'               => 'required|exists:suppliers,id',
            'order_date'                => 'required|date',
            'delivery_date'             => 'nullable|date',
            'notes'                     => 'nullable|string',
            'type'                      => 'required|string|in:operasional,karton,kayu',
            'ppn_percentage'            => 'required|numeric|in:0,11,11.12,12',
            'currency'                  => 'nullable|string|in:IDR,USD,EUR',
            'exchange_rate'             => 'nullable|numeric|min:1',
            'other_cost'                => 'nullable|numeric|min:0',
            'other_cost_description'    => 'nullable|string|max:255',
            'details'                   => 'required|array|min:1',
            'details.*.item_id'         => 'required|exists:items,id',
            'details.*.quantity'        => 'required|numeric|min:0.01',
            'details.*.price'           => 'required|numeric|min:0',
            'details.*.specifications'  => 'nullable|array',
            'no_surat_jalan'            => 'nullable|string|max:100',
            'details.*.delivery_date'   => 'nullable|date',
        ]);
    }
    private function calculateTotals(array $details, float $ppnPercentage, float $exchangeRate = 1, float $otherCost = 0): array
    {
        $subtotal = collect($details)->sum(fn($item) => $item['quantity'] * $item['price'] * $exchangeRate);

        // Special case: 11.12 → hitung pakai 11%
        $actualPpnRate = ($ppnPercentage == 11.12) ? 11 : $ppnPercentage;

        $ppnAmount  = $subtotal * ($actualPpnRate / 100);
        $grandTotal = $subtotal + $ppnAmount + $otherCost;

        return [
            'subtotal'       => $subtotal,
            'ppn_percentage' => $ppnPercentage,
            'ppn_amount'     => $ppnAmount,
            'other_cost'     => $otherCost,
            'grand_total'    => $grandTotal,
        ];
    }

    // price disimpan dalam currency asli (USD/IDR), subtotal selalu IDR
    private function prepareDetails(array $details, float $exchangeRate = 1): array
    {
        return collect($details)->map(function ($item) use ($exchangeRate) {
            return [
                'item_id'          => $item['item_id'],
                'quantity_ordered' => $item['quantity'],
                'price'            => $item['price'],
                'subtotal'         => $item['quantity'] * $item['price'] * $exchangeRate,
                'delivery_date'    => $item['delivery_date'] ?? null,
                'specifications'   => isset($item['specifications']) ? json_encode($item['specifications']) : null,
            ];
        })->all();
    }

    private function generatePoNumber()
    {
        $year = date('Y');
        $month = date('n');
        $romanMonth = $this->toRoman($month);

        $lastOrder = PurchaseOrder::whereYear('created_at', $year)
            ->orderByRaw("CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(po_number, '.', 2), '.', -1) AS UNSIGNED) DESC")
            ->first();

        $counter = 1;
        if ($lastOrder && preg_match('/No\.(\d{3})\/PO-SBC\//', $lastOrder->po_number, $matches)) {
            $counter = intval($matches[1]) + 1;
        }

        $counterPadded = str_pad($counter, 3, '0', STR_PAD_LEFT);
        $candidate = "No.{$counterPadded}/PO-SBC/{$romanMonth}/{$year}";

        while (PurchaseOrder::where('po_number', $candidate)->exists()) {
            $counter++;
            $counterPadded = str_pad($counter, 3, '0', STR_PAD_LEFT);
            $candidate = "No.{$counterPadded}/PO-SBC/{$romanMonth}/{$year}";
        }

        return $candidate;
    }

    private function toRoman(int $month): string
    {
        $romanNumerals = [
            1 => 'I',    2 => 'II',   3 => 'III',  4 => 'IV',
            5 => 'V',    6 => 'VI',   7 => 'VII',  8 => 'VIII',
            9 => 'IX',   10 => 'X',   11 => 'XI',  12 => 'XII',
        ];

        return $romanNumerals[$month] ?? 'I';
    }
}
