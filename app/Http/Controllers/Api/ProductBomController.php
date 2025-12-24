<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\ProductBomImport;
use App\Models\ProductBom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ProductBomController extends Controller
{
    // 🔹 LANGKAH 2: untuk halaman index Vue
    public function index()
    {
        $rows = ProductBom::selectRaw('parent_item_id, COUNT(*) as components_count')
            ->groupBy('parent_item_id')
            ->with('parentItem') // pastikan relasi ada di model ProductBom
            ->get()
            ->map(function ($row) {
                return [
                    'item_id'          => $row->parent_item_id,
                    'item_name'        => $row->parentItem?->name,
                    'components_count' => (int) $row->components_count,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $rows,
        ]);
    }

    // 🔹 LANGKAH 4: import Excel BOM
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        try {
            Excel::import(new ProductBomImport, $request->file('file'));

            return response()->json([
                'success' => true,
                'message' => 'Import BOM berhasil diproses.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error import BOM: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat import BOM: '.$e->getMessage(),
            ], 500);
        }
    }
}
