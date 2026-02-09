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
                'items.bentuk',
                'items.jenis_kayu',
                'items.tpk',
                'items.diameter',
                'items.panjang',
                'items.kubikasi',
                'items.tanggal_terima',
                'items.no_skshhk',
                'items.no_kapling',
                'items.mutu'
            )
                ->with([
                    'unit:id,name',
                    'category:id,name',
                    'inventories:id,item_id,warehouse_id,qty_pcs,qty_m3',
                    'inventories.warehouse:id,name,code',
                ])
                ->whereIn('items.category_id', $categoryIds);

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('items.name', 'like', "%{$search}%")
                        ->orWhere('items.code', 'like', "%{$search}%");
                });
            }

            if ($request->filled('tpk')) {
                $query->where('items.tpk', $request->input('tpk'));
            }

            if ($request->filled('jenis_kayu')) {
                $query->where('items.jenis_kayu', $request->input('jenis_kayu'));
            }

            if ($request->filled('mutu')) {
                $query->where('items.mutu', $request->input('mutu'));
            }

            $sortBy = $request->input('sort_by', 'name');
            $sortOrder = $request->input('sort_order', 'asc');
            $allowedSortFields = ['name', 'code', 'created_at', 'tanggal_terima'];

            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy("items.$sortBy", $sortOrder);
            } else {
                $query->orderBy('items.name', 'asc');
            }

            // Clone query untuk hitung total kubikasi SEBELUM paginate
            $sumQuery = clone $query;
            $warehouseId = $request->input('warehouse_id');
            $totalKubikasi = 0;

            // Hitung total kubikasi dari SEMUA data yang ter-filter
            $allItemsForSum = $sumQuery->with([
                'category:id,name',
                'inventories:id,item_id,warehouse_id,qty_pcs,qty_m3',
            ])->get();

            foreach ($allItemsForSum as $item) {
                $allInventories = $item->inventories ?? collect();

                if ($warehouseId) {
                    $filteredInventories = $allInventories->filter(function ($inv) use ($warehouseId) {
                        return (int) $inv->warehouse_id === (int) $warehouseId;
                    });
                    $inventories = $filteredInventories;
                } else {
                    $inventories = $allInventories;
                }

                $totalFromStocks = $inventories->sum(function ($inv) {
                    return (float) ($inv->qty_pcs ?? 0);
                });

                if ($totalFromStocks == 0) {
                    $totalFromStocks = (float) ($item->stock ?? 0);
                }

                $m3PerPcs = 0;
                if (is_array($item->specifications) && isset($item->specifications['m3_per_pcs'])) {
                    $m3PerPcs = (float) $item->specifications['m3_per_pcs'];
                }

                $volumeM3 = $totalFromStocks * $m3PerPcs;

                if (str_contains(strtolower($item->category->name ?? ''), 'kayu log')) {
                    $volumeM3 = (float) ($item->kubikasi ?? 0);
                }

                $totalKubikasi += $volumeM3;
            }

            // Baru paginate untuk display
            $perPage = min($request->input('per_page', 50), 100);
            $items = $query->paginate($perPage);

            $collection = $items->getCollection()->transform(function ($item) use ($warehouseId) {
                $allInventories = $item->inventories ?? collect();

                if ($warehouseId) {
                    $filteredInventories = $allInventories->filter(function ($inv) use ($warehouseId) {
                        return (int) $inv->warehouse_id === (int) $warehouseId;
                    })->values();

                    $item->setRelation('inventories', $filteredInventories);
                    $item->stocks = $filteredInventories;
                    $inventories = $filteredInventories;
                } else {
                    $item->stocks = $allInventories;
                    $inventories = $allInventories;
                }

                $totalFromStocks = $inventories->sum(function ($inv) {
                    return (float) ($inv->qty_pcs ?? 0);
                });

                if ($totalFromStocks == 0) {
                    $totalFromStocks = (float) ($item->stock ?? 0);
                }

                $item->total_stock_from_stocks = $totalFromStocks;

                $m3PerPcs = 0;
                if (is_array($item->specifications) && isset($item->specifications['m3_per_pcs'])) {
                    $m3PerPcs = (float) $item->specifications['m3_per_pcs'];
                }

                $item->total_volume_m3 = $totalFromStocks * $m3PerPcs;

                if (str_contains(strtolower($item->category->name ?? ''), 'kayu log')) {
                    $item->total_volume_m3 = (float) ($item->kubikasi ?? 0);
                }

                return $item;
            });

            if ($warehouseId) {
                $collection = $collection->filter(function ($item) {
                    return ($item->total_stock_from_stocks ?? 0) > 0;
                })->values();

                $items->setCollection($collection);
            }

            return response()->json([
                'success' => true,
                'data' => $items,
                'summary' => [
                    'total_kubikasi' => round($totalKubikasi, 4)
                ]
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