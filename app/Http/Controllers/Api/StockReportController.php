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

            $categoryIds = Category::where(function ($query) use ($categoryNames) {
                foreach ($categoryNames as $name) {
                    $query->orWhereRaw('LOWER(name) = ?', [strtolower(trim($name))]);
                }
            })->pluck('id');

            if ($categoryIds->isEmpty()) {
                return response()->json(['success' => true, 'data' => []]);
            }

            // 2. Query items + relasi stocks
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
                    'stocks:id,item_id,warehouse_id,quantity',
                    'stocks.warehouse:id,name,code',
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

            // 6. Hitung total stok (dari tabel stocks) + kubikasi
            $items->getCollection()->transform(function ($item) {
                // total stok dari relasi stocks
                $totalFromStocks = ($item->stocks ?? collect())->sum(function ($s) {
                    return (float) ($s->quantity ?? 0);
                });

                $item->total_stock_from_stocks = $totalFromStocks;

                // kubikasi (kalau ada m3_per_pcs di specifications)
                $m3PerPcs = 0;
                if (is_array($item->specifications) && isset($item->specifications['m3_per_pcs'])) {
                    $m3PerPcs = (float) $item->specifications['m3_per_pcs'];
                }

                $item->total_volume_m3 = $totalFromStocks * $m3PerPcs;

                return $item;
            });

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
