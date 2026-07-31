<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Warehouse;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class MaterialUsageController extends Controller
{
    const CONSUMABLE_CATEGORY_IDS = [
    
        3,  // Bahan Operasional
        4,  // Karton Box
        
    
    ];

    public function getConsumableItems(Request $request): JsonResponse
    {
        try {
            $query = Item::with(['unit:id,name', 'category:id,name'])
                ->select('id', 'code', 'name', 'type', 'unit_id', 'category_id', 'stock', 'price')
                ->orderBy('category_id')
                ->orderBy('name');

            if ($request->filled('category_id')) {
                $query->where('category_id', $request->category_id);
            } else {
                $query->whereIn('category_id', self::CONSUMABLE_CATEGORY_IDS);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('code', 'LIKE', "%{$search}%");
                });
            }

            $items = $query->get();

            Log::info('Consumable items found: ' . $items->count());

            return response()->json([
                'success' => true,
                'data' => $items,
                'count' => $items->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Gagal mengambil daftar bahan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data barang.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getConsumableCategories(): JsonResponse
    {
        try {
            $categories = Category::whereIn('id', self::CONSUMABLE_CATEGORY_IDS)
                ->select('id', 'name')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            Log::error('Gagal mengambil kategori: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data kategori.'
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
                    'message' => "Stok barang tidak cukup! Tersedia: {$item->stock} {$item->unit->name}, Diminta: {$request->qty} {$item->unit->name}"
                ], 422);
            }

            $item->decrement('stock', $request->qty);

            try {
                $deductedByWarehouse = $this->decrementInventoryFifo($item->id, $request->qty);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            $logs = [];
            foreach ($deductedByWarehouse as $warehouseId => $qtyDeducted) {
                $logs[] = InventoryLog::create([
                    'date' => $request->date ?? now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'item_id' => $item->id,
                    'warehouse_id' => $warehouseId,
                    'qty' => $qtyDeducted,
                    'direction' => 'OUT',
                    'transaction_type' => InventoryLog::TYPE_USAGE,
                    'division' => $request->division,
                    'notes' => $request->notes ?? "Dipakai divisi {$request->division}",
                    'user_id' => Auth::id(),
                ]);
            }

            DB::commit();

            $primaryLog = $logs[0]->load(['item:id,name,code', 'user:id,name']);

            return response()->json([
                'success' => true,
                'message' => "Berhasil mencatat pemakaian {$request->qty} {$item->unit->name} {$item->name} untuk divisi {$request->division}.",
                'data' => $primaryLog
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
    'item:id,name,code,price',
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

    /**
     * Kurangi qty_pcs FIFO lintas SEMUA gudang tempat item ini benar-benar
     * punya stok (bukan gudang divisi pemakai - divisi cuma label pelapor,
     * bukan lokasi fisik barang). Return [warehouse_id => qty_deducted]
     * supaya InventoryLog dicatat ke gudang yang benar-benar berkurang.
     */
    private function decrementInventoryFifo(int $itemId, float $qty): array
    {
        $inventories = Inventory::where('item_id', $itemId)
            ->where('qty_pcs', '>', 0)
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->get();

        $totalAvailable = $inventories->sum('qty_pcs');
        if ($totalAvailable < $qty) {
            throw new \Exception("Stok fisik per-gudang tidak cukup (tersedia: {$totalAvailable}, diminta: {$qty}).");
        }

        $remaining = $qty;
        $deductedByWarehouse = [];

        /** @var Inventory $inventory */
        foreach ($inventories as $inventory) {
            if ($remaining <= 0) break;

            $toDeduct = min($remaining, $inventory->qty_pcs);
            $inventory->decrement('qty_pcs', $toDeduct);
            $remaining -= $toDeduct;

            $deductedByWarehouse[$inventory->warehouse_id] = ($deductedByWarehouse[$inventory->warehouse_id] ?? 0) + $toDeduct;
        }

        return $deductedByWarehouse;
    }
}
