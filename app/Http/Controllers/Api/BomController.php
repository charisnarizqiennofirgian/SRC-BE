<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\ProductBomImport;
use App\Models\Item;
use App\Models\ProductBom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BomController extends Controller
{
    // Untuk halaman index: list produk yang sudah punya BOM
    public function index()
    {
        $rows = ProductBom::selectRaw('parent_item_id as item_id, COUNT(*) as components_count')
            ->groupBy('parent_item_id')
            ->with('parentItem')
            ->get()
            ->map(function ($row) {
                return [
                    'item_id'          => $row->item_id,
                    'item_name'        => $row->parentItem?->name,
                    'components_count' => $row->components_count,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $rows,
        ]);
    }

    // Import Excel BOM
    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi file gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            (new ProductBomImport)->import($request->file('file'));

            return response()->json([
                'success' => true,
                'message' => 'Import BOM selesai.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal import: ' . $e->getMessage(),
            ], 500);
        }
    }
}
