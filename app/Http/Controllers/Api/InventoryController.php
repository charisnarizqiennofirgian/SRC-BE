<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $warehouseId = $request->input('warehouse_id');
        $categoryId  = $request->input('category_id');
        $search      = $request->input('search');
        $perPage     = min($request->input('per_page', 50), 9999);

        $query = Inventory::with(['item.category', 'item.unit', 'warehouse'])
            ->where('qty', '>', 0); // ✅ PAKAI 'qty'

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

        // Search by item name or code
        if ($search) {
            $query->whereHas('item', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $inventories = $query
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $inventories,
        ]);
    }
}