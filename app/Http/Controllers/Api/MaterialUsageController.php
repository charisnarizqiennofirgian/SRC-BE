<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class MaterialUsageController extends Controller
{
    const DIVISIONS = [
        'Assembling',
        'Sanding',
        'Rustik',
        'Finishing',
        'Packing',
        'Maintenance',
        'Produksi Umum',
    ];

   public function getConsumableItems(): JsonResponse
{
    try {
        $items = Item::where('category_id', 7)
            ->with(['unit:id,name', 'category:id,name'])
            ->select('id', 'code', 'name', 'type', 'unit_id', 'category_id', 'stock')
            ->orderBy('name')
            ->get();

        Log::info('Items found: ' . $items->count());

        return response()->json([
            'success' => true,
            'data' => $items,
            'count' => $items->count()
        ]);
    } catch (\Exception $e) {
        Log::error('Gagal mengambil daftar bahan operasional: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil data barang.',
            'error' => $e->getMessage()
        ], 500);
    }
}
public function getDivisions(): JsonResponse
{
    try {
        $warehouses = Warehouse::select('id', 'name')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $warehouses
        ]);
    } catch (\Exception $e) {
        Log::error('Gagal mengambil daftar divisi: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil data divisi.'
        ], 500);
    }
}

    public function checkStock(int $itemId): JsonResponse
{
    try {
        $item = Item::with('unit:id,name')->findOrFail($itemId);

        return response()->json([
            'success' => true,
            'data' => [
                'item' => $item,
                'stock' => (float) $item->stock,
            ]
        ]);
    } catch (\Exception $e) {
        Log::error('Gagal cek stok: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengecek stok.'
        ], 500);
    }
}
    public function store(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
    'item_id'   => 'required|exists:items,id',
    'qty'       => 'required|numeric|min:0.0001',
    'division'  => 'required|string|max:255',
    'notes'     => 'nullable|string|max:500',
    'date'      => 'nullable|date',
]);


    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Data tidak valid.',
            'errors' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();
    try {
        $item = Item::with('unit')->findOrFail($request->item_id);

        if ($item->stock < $request->qty) {
            return response()->json([
                'success' => false,
                'message' => "Stok barang operasional tidak cukup! Tersedia: {$item->stock} {$item->unit->name}, Diminta: {$request->qty} {$item->unit->name}"
            ], 422);
        }

        $item->decrement('stock', $request->qty);

        $warehouseId = 1;

        $log = InventoryLog::create([
            'date' => $request->date ?? now()->toDateString(),
            'time' => now()->toTimeString(),
            'item_id' => $item->id,
            'warehouse_id' => $warehouseId,
            'qty' => $request->qty,
            'direction' => 'OUT',
            'transaction_type' => InventoryLog::TYPE_USAGE,
            'division' => $request->division,
            'notes' => $request->notes ?? "Dipakai divisi {$request->division}",
            'user_id' => auth()->id(),
        ]);

        DB::commit();

        $log->load(['item:id,name,code', 'user:id,name']);

        return response()->json([
            'success' => true,
            'message' => "Berhasil mencatat pemakaian {$request->qty} {$item->unit->name} {$item->name} untuk divisi {$request->division}.",
            'data' => $log
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Gagal menyimpan pemakaian bahan: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Terjadi kesalahan saat menyimpan data.',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

    public function index(Request $request): JsonResponse
    {
        try {
            $query = InventoryLog::with([
                    'item:id,name,code',
                    'warehouse:id,name',
                    'user:id,name'
                ])
                ->where('transaction_type', InventoryLog::TYPE_USAGE)
                ->orderBy('date', 'desc')
                ->orderBy('time', 'desc');

            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->byDateRange($request->start_date, $request->end_date);
            }

            if ($request->filled('division')) {
                $query->where('division', $request->division);
            }

            if ($request->filled('item_id')) {
                $query->byItem($request->item_id);
            }

            $logs = $query->paginate($request->input('per_page', 25));

            return response()->json([
                'success' => true,
                'data' => $logs
            ]);
        } catch (\Exception $e) {
            Log::error('Gagal mengambil riwayat pemakaian: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data.'
            ], 500);
        }
    }

    private function decrementInventory(int $itemId, int $warehouseId, float $qty): void
    {
        $inventories = Inventory::where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->where('qty_pcs', '>', 0)
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->get();

        $remaining = $qty;

        foreach ($inventories as $inventory) {
            if ($remaining <= 0) break;

            $toDeduct = min($remaining, $inventory->qty_pcs);
            $inventory->decrement('qty_pcs', $toDeduct);
            $remaining -= $toDeduct;
        }
    }
}
