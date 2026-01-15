<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $warehouseId = $request->input('warehouse_id');
        $categoryId  = $request->input('category_id');
        $itemType    = $request->input('item_type'); // ✅ TAMBAH INI
        $search      = $request->input('search');
        $perPage     = min($request->input('per_page', 50), 9999);

        // ✅ UBAH: Group by item_id dan sum qty
        $query = Inventory::query()
            ->select([
                'item_id',
                'warehouse_id',
                DB::raw('SUM(qty) as total_qty'),
                DB::raw('GROUP_CONCAT(DISTINCT ref_po_id) as ref_po_ids'), // Gabung semua PO
                DB::raw('MAX(id) as latest_id'), // Ambil ID terbaru untuk reference
            ])
            ->with(['item.category', 'item.unit', 'warehouse'])
            ->where('qty', '>', 0)
            ->groupBy('item_id', 'warehouse_id');

        // Filter by warehouse
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        // Filter by category
        if ($categoryId) {
            $query->whereHas('item', function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId);
            });
        }

        // ✅ TAMBAH: Filter by item type (material/component)
        if ($itemType) {
            $query->whereHas('item', function ($q) use ($itemType) {
                $q->where('type', $itemType);
            });
        }

        // Search by item name or code
        if ($search) {
            $query->whereHas('item', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $inventories = $query
            ->orderBy('latest_id', 'desc')
            ->paginate($perPage);

        // ✅ Format response biar konsisten
        $inventories->getCollection()->transform(function ($inv) {
            return [
                'id'           => $inv->latest_id,
                'warehouse_id' => $inv->warehouse_id,
                'item_id'      => $inv->item_id,
                'qty'          => $inv->total_qty, //  Total dari semua baris
                'ref_po_id'    => $inv->ref_po_ids, // Gabungan PO (23, 45, 67)
                'ref_product_id' => null, // Karena bisa beda-beda
                'item'         => $inv->item,
                'warehouse'    => $inv->warehouse,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $inventories,
        ]);
    }
}
