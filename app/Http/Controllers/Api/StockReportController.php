<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StockReportController extends Controller
{
    public function index(Request $request)
    {
        try {
            // 1. Filter kategori wajib
            if (!$request->has('categories')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Filter kategori diperlukan.'
                ], 400);
            }

            $categoryNames = explode(',', $request->query('categories'));

            $categories = Category::where(function ($query) use ($categoryNames) {
                foreach ($categoryNames as $name) {
                    $query->orWhereRaw('LOWER(name) = ?', [strtolower(trim($name))]);
                }
            })->get();

            $categoryIds = $categories->pluck('id');

            if ($categoryIds->isEmpty()) {
                return response()->json(['success' => true, 'data' => []]);
            }

            // 2. Query items + relasi inventories (BUKAN stocks)
            $query = Item::select(
                    'items.id',
                    'items.name',
                    'items.code',
                    'items.unit_id',
                    'items.category_id',
                    'items.stock',
                    'items.specifications',
                    'items.jenis',
                    'items.kualitas',
                    'items.bentuk'
                )
                ->with([
                    'unit:id,name',
                    'category:id,name',
                    'inventories:id,item_id,warehouse_id,qty', // ✅ GANTI stocks → inventories
                    'inventories.warehouse:id,name,code',      // ✅ GANTI stocks.warehouse → inventories.warehouse
                ])
                ->whereIn('items.category_id', $categoryIds);

            // 3. Search
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('items.name', 'like', "%{$search}%")
                        ->orWhere('items.code', 'like', "%{$search}%");
                });
            }

            // 4. Sorting
            $sortBy    = $request->input('sort_by', 'name');
            $sortOrder = $request->input('sort_order', 'asc');
            $allowedSortFields = ['name', 'code', 'created_at'];

            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy("items.$sortBy", $sortOrder);
            } else {
                $query->orderBy('items.name', 'asc');
            }

            // 5. Pagination
            $perPage = min($request->input('per_page', 50), 100);
            $items   = $query->paginate($perPage);

            $warehouseId = $request->input('warehouse_id');

            // 6. Hitung total stok + kubikasi
            $collection = $items->getCollection()->transform(function ($item) use ($warehouseId) {
                // ✅ Ganti stocks → inventories
                $allInventories = $item->inventories ?? collect();
                
                if ($warehouseId) {
                    $filteredInventories = $allInventories->filter(function ($inv) use ($warehouseId) {
                        return (int) $inv->warehouse_id === (int) $warehouseId;
                    })->values();
                    
                    $item->setRelation('inventories', $filteredInventories);
                    $item->stocks = $filteredInventories; // ✅ Set stocks untuk frontend
                    $inventories = $filteredInventories;
                } else {
                    $item->stocks = $allInventories; // ✅ Set stocks untuk frontend
                    $inventories = $allInventories;
                }

                $totalFromStocks = $inventories->sum(function ($inv) {
                    return (float) ($inv->qty ?? 0);
                });

                // fallback
                if ($totalFromStocks == 0) {
                    $totalFromStocks = (float) ($item->stock ?? 0);
                }

                $item->total_stock_from_stocks = $totalFromStocks;

                $m3PerPcs = 0;
                if (is_array($item->specifications) && isset($item->specifications['m3_per_pcs'])) {
                    $m3PerPcs = (float) $item->specifications['m3_per_pcs'];
                }

                $item->total_volume_m3 = $totalFromStocks * $m3PerPcs;

                return $item;
            });

            // 7. Filter item dengan stok 0
            if ($warehouseId) {
                $collection = $collection->filter(function ($item) {
                    return ($item->total_stock_from_stocks ?? 0) > 0;
                })->values();

                $items->setCollection($collection);
            }

            return response()->json([
                'success' => true,
                'data'    => $items,
            ]);
        } catch (\Exception $e) {
            Log::error('Gagal mengambil laporan stok: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan pada server.'
            ], 500);
        }
    }
}
