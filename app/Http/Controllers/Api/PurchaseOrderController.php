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

    private function validatePurchaseOrder(Request $request)
    {
        return Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'delivery_date' => 'nullable|date',
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

    // ✅ UPDATED: FORMAT BARU PO/001/PO-SBC/II/2026
    private function generatePoNumber()
    {
        $year = date('Y');
        $month = date('n'); // 1-12
        $romanMonth = $this->toRoman($month);

        // Get counter untuk bulan & tahun ini
        $lastOrder = PurchaseOrder::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        $counter = 1;
        if ($lastOrder && preg_match('/PO\/(\d{3})\/PO-SBC\//', $lastOrder->po_number, $matches)) {
            $counter = intval($matches[1]) + 1;
        }

        $counterPadded = str_pad($counter, 3, '0', STR_PAD_LEFT);

        return "No.{$counterPadded}/PO-SBC/{$romanMonth}/{$year}";
    }

    // ✅ HELPER: Convert bulan ke Romawi
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
