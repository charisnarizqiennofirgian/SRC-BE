<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;

class WarehouseController extends Controller
{
    public function index()
    {
        $warehouses = Warehouse::orderBy('name')->get(['id','name','code']);

        return response()->json([
            'success' => true,
            'data' => $warehouses,
        ]);
    }
}
