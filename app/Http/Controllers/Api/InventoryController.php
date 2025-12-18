<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    // List stok per gudang (dipakai Candy: gudang Sanwil)
    public function index(Request $request)
    {
        $warehouseId = $request->input('warehouse_id'); // wajib untuk Candy
        $search      = $request->input('search');       // optional
        $perPage     = min($request->input('per_page', 50), 100);

        $query = Inventory::with(['item:id,code,name', 'warehouse:id,name'])
            ->when($warehouseId, function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            })
            ->where('qty', '>', 0); // hanya stok > 0

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
