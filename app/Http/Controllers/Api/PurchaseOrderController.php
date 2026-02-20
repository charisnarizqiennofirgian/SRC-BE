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

            $totals = $this->calculateTotals($validatedData['details'], $validatedData['ppn_percentage']);

            $order = PurchaseOrder::create([
                'po_number' => $this->generatePoNumber(),
                'supplier_id' => $validatedData['supplier_id'],
                'order_date' => $validatedData['order_date'],
                'delivery_date' => $validatedData['delivery_date'] ?? null,
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

        $purchaseOrder->details->each(function ($detail) {
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

            $totals = $this->calculateTotals($validatedData['details'], $validatedData['ppn_percentage']);

            $purchaseOrder->update([
                'supplier_id' => $validatedData['supplier_id'],
                'order_date' => $validatedData['order_date'],
                'delivery_date' => $validatedData['delivery_date'] ?? null,
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
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'type' => 'required|string|in:operasional,karton,kayu',
            'ppn_percentage' => 'required|numeric|in:0,11,11.12,12',  // ✅ TAMBAH 11.12
            'details' => 'required|array|min:1',
            'details.*.item_id' => 'required|exists:items,id',
            'details.*.quantity' => 'required|numeric|min:0.01',
            'details.*.price' => 'required|numeric|min:0',
            'details.*.specifications' => 'nullable|array',
        ]);
    }

    // ✅ UPDATED: DETEKSI 11.12 → HITUNG PAKAI 11%
    private function calculateTotals(array $details, float $ppnPercentage): array
    {
        $subtotal = collect($details)->sum(fn($item) => $item['quantity'] * $item['price']);

        // Special case: 11.12 → hitung pakai 11%
        $actualPpnRate = ($ppnPercentage == 11.12) ? 11 : $ppnPercentage;

        $ppnAmount = $subtotal * ($actualPpnRate / 100);
        $grandTotal = $subtotal + $ppnAmount;

        return [
            'subtotal' => $subtotal,
            'ppn_percentage' => $ppnPercentage,  // Simpan 11.12 untuk display
            'ppn_amount' => $ppnAmount,           // Tapi hitung pakai 11%
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
        $year = date('Y');
        $month = date('n');
        $romanMonth = $this->toRoman($month);

        $lastOrder = PurchaseOrder::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        $counter = 1;
        if ($lastOrder && preg_match('/No\.(\d{3})\/PO-SBC\//', $lastOrder->po_number, $matches)) {
            $counter = intval($matches[1]) + 1;
        }

        $counterPadded = str_pad($counter, 3, '0', STR_PAD_LEFT);

        return "No.{$counterPadded}/PO-SBC/{$romanMonth}/{$year}";
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
