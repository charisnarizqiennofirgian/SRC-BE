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
        $itemType    = $request->input('item_type');
        $search      = $request->input('search');
        $perPage     = min($request->input('per_page', 50), 9999);

        $query = Inventory::query()
            ->select([
                'item_id',
                'warehouse_id',
                DB::raw('SUM(qty_pcs) as total_qty'),
                DB::raw('GROUP_CONCAT(DISTINCT ref_po_id) as ref_po_ids'),
                DB::raw('MAX(id) as latest_id'),
            ])
            ->with(['item.category', 'item.unit', 'warehouse'])
            ->where('qty_pcs', '>', 0)
            ->groupBy('item_id', 'warehouse_id');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($categoryId) {
            $query->whereHas('item', function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId);
            });
        }

        if ($itemType) {
            $query->whereHas('item', function ($q) use ($itemType) {
                $q->where('type', $itemType);
            });
        }

        if ($search) {
            $query->whereHas('item', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $inventories = $query
            ->orderBy('latest_id', 'desc')
            ->paginate($perPage);

        $inventories->getCollection()->transform(function ($inv) {
            return [
                'id'           => $inv->latest_id,
                'warehouse_id' => $inv->warehouse_id,
                'item_id'      => $inv->item_id,
                'qty'          => $inv->total_qty,
                'ref_po_id'    => $inv->ref_po_ids,
                'ref_product_id' => null,
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
