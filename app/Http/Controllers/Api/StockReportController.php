<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

class StockReportController extends Controller
{
    /**
     * ✅ Get Stock Report dengan Filter Jenis Barang
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $search = $request->input('search');
            $type = $request->input('type'); // ✅ TAMBAH: Filter jenis barang
            $perPage = $request->input('per_page', 15);

            $query = Item::query();

            // ✅ SELECT dengan SUM stock dari stock_movements
            $query->select(
                'items.id',
                'items.code',
                'items.name',
                'items.category_id',
                'items.unit_id',
                DB::raw('COALESCE((SELECT SUM(quantity) FROM stock_movements WHERE stock_movements.item_id = items.id), 0) as stock')
            );

            // ✅ EAGER LOAD: category dan unit
            $query->with(['category', 'unit']);

            // ✅ FILTER BERDASARKAN TYPE
            if ($type === 'bahan_baku') {
                // Filter: Bahan Baku, Bahan Penolong, Bahan Operasional
                $query->whereHas('category', function($q) {
                    $q->where('name', 'like', '%Bahan%')
                      ->orWhere('name', 'like', '%Penolong%')
                      ->orWhere('name', 'like', '%Operasional%');
                });
            } elseif ($type === 'produk_jadi') {
                // Filter: Produk Jadi
                $query->whereHas('category', function($q) {
                    $q->where('name', 'like', '%Produk Jadi%');
                });
            }
            // Jika type kosong/null, tampilkan semua

            // ✅ SEARCH: Berdasarkan nama atau kode
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('items.name', 'like', "%{$search}%")
                      ->orWhere('items.code', 'like', "%{$search}%");
                });
            }

            // ✅ ORDER BY: Urutkan berdasarkan nama
            $query->orderBy('items.name');

            // ✅ PAGINATION
            $reportData = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $reportData
            ]);

        } catch (\Exception $e) {
            \Log::error('❌ Error saat mengambil laporan stok: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil laporan stok.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
