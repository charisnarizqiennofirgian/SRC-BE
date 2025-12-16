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

            // 2. Query tanpa gudang, pakai items.stock
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

            // 6. Hitung kubikasi per item
            $items->getCollection()->transform(function ($item) {
                $m3PerPcs = $item->specifications['m3_per_pcs'] ?? 0;
                $qty      = (float) ($item->stock ?? 0);

                $item->total_volume_m3 = $qty * $m3PerPcs;

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
