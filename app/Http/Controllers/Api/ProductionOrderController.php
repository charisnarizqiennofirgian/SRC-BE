<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionOrderController extends Controller
{
    public function storeFromSalesOrder(Request $request, SalesOrder $salesOrder)
    {
        // nanti di sini: generate nomor PO, simpan header + detail
        return response()->json([
            'success' => true,
            'message' => 'Stub endpoint – siap diisi logic.',
            'sales_order_id' => $salesOrder->id,
        ]);
    }
}
