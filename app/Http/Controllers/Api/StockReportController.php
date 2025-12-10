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
            // Wajib ada filter kategori
            if (!$request->has('categories')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Filter kategori diperlukan.'
                ], 400);
            }

            // categories = "Kayu Logs,Kayu RST"
            $categoryNames = explode(',', $request->query('categories'));

            // Cari id kategori dengan nama yang cocok (case-insensitive)
            $categoryIds = Category::where(function ($query) use ($categoryNames) {
                foreach ($categoryNames as $name) {
                    $query->orWhereRaw('LOWER(name) = ?', [strtolower(trim($name))]);
                }
            })->pluck('id');

            if ($categoryIds->isEmpty()) {
                return response()->json(['success' => true, 'data' => []]);
            }

            // Query item + relasi unit, category, stocks.warehouse
            $query = Item::with([
                    'unit:id,name',
                    'category:id,name',
                    'stocks.warehouse:id,name,code', // 🔹 relasi stok per gudang
                ])
                ->select(
                    'id',
                    'name',
                    'code',
                    'unit_id',
                    'category_id',
                    'stock',          // masih disertakan untuk kompatibilitas lama
                    'specifications',
                    'jenis',
                    'kualitas',
                    'bentuk'
                )
                ->whereIn('category_id', $categoryIds);

            // Pencarian
            if ($request->has('search') && $request->input('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'name');
            $sortOrder = $request->input('sort_order', 'asc');

            $allowedSortFields = ['name', 'code', 'stock', 'created_at'];
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('name', 'asc');
            }

            // Pagination
            $perPage = min($request->input('per_page', 50), 100);
            $items = $query->paginate($perPage);

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
