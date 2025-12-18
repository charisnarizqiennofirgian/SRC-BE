<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\BuyerController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\StockReportController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\GoodsReceiptController;
use App\Http\Controllers\Api\PurchaseBillController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\DeliveryOrderController;
use App\Http\Controllers\Api\BomController;               
use App\Http\Controllers\Api\ProductionController;        
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\SawmillProductionController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\CandyProductionController;
use App\Http\Controllers\Api\ProductionOrderController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==========================================
// PUBLIC ROUTES (Tidak Perlu Login)
// ==========================================

Route::post('/login', [AuthController::class, 'login']);

Route::get('/password-policy', function () {
    return response()->json([
        'success' => true,
        'policy' => [
            'min_length' => config('password_policy.min_length', 3),
            'max_length' => config('password_policy.max_length', 255),
            'require_uppercase' => config('password_policy.require_uppercase', false),
            'require_lowercase' => config('password_policy.require_lowercase', false),
            'require_numbers' => config('password_policy.require_numbers', false),
            'require_symbols' => config('password_policy.require_symbols', false),
            'allow_common_passwords' => config('password_policy.allow_common_passwords', true),
        ]
    ]);
});

// ==========================================
// PROTECTED ROUTES (Perlu Login)
// ==========================================

Route::middleware('auth:sanctum')->group(function () {

    // --- USER INFO ---
    Route::get('/user', function (Request $request) {
        $user = $request->user();
        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')
        ];
    });

    // --- PENGATURAN ---
    Route::apiResource('roles', RoleController::class)->only(['index', 'store']);
    Route::apiResource('users', UserController::class)->only(['store']);

    // --- MASTER DATA ---
    Route::get('/categories/all', [CategoryController::class, 'all']);
    Route::apiResource('categories', CategoryController::class);

    Route::get('/units/all', [UnitController::class, 'all']);
    Route::apiResource('units', UnitController::class);

    Route::apiResource('suppliers', SupplierController::class);
    Route::apiResource('buyers', BuyerController::class);

    Route::post('/products/import', [ProductController::class, 'import']);
    Route::apiResource('products', ProductController::class);

    Route::post('/materials/import', [MaterialController::class, 'import']);
    Route::get('/materials/template', [MaterialController::class, 'downloadTemplate']);
    Route::apiResource('materials', MaterialController::class);

    // --- MANAJEMEN STOK ---
    Route::get('/stock-report', [StockReportController::class, 'index']);
    Route::get('reports/sawmill-yield', [\App\Http\Controllers\Api\SawmillReportController::class, 'index']);
    Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store']);
    Route::post('/stock-adjustments/upload', [StockAdjustmentController::class, 'upload']);
    Route::post('/stock-adjustments/upload-saldo-awal-kayu', [StockAdjustmentController::class, 'uploadSaldoAwalKayu']);
    Route::get('/stock-adjustments/template-kayu', [StockAdjustmentController::class, 'downloadTemplateKayu']);
    Route::get('/stock-adjustments/template-kayu-rst', [StockAdjustmentController::class, 'downloadTemplateKayuRst']);
    Route::post('/materials/import-kayu-rst', [StockAdjustmentController::class, 'uploadSaldoAwalKayuRst']);
    Route::get('/stock-adjustments/template-umum', [StockAdjustmentController::class, 'downloadTemplateUmum']);
    Route::get('/stock-adjustments/template-produk-jadi', [StockAdjustmentController::class, 'downloadProdukJadiTemplate']);
    Route::get('/stock-adjustments/template-saldo-awal-produk-jadi', [StockAdjustmentController::class, 'downloadTemplateSaldoAwalProdukJadi']);
    Route::post('/stock-adjustments/upload-saldo-awal-produk-jadi', [StockAdjustmentController::class, 'uploadSaldoAwalProdukJadi']);
    Route::post('/stock-adjustments/upload-produk-jadi', [StockAdjustmentController::class, 'uploadSaldoAwalProdukJadi']);

    Route::prefix('stock-adjustments')->group(function () {
        // sudah ada:
        // Route::get('/template-umum', [StockAdjustmentController::class, 'downloadTemplateUmum']);
        // Route::post('/upload-saldo-awal-umum', [StockAdjustmentController::class, 'uploadSaldoAwalUmum']);
        // dst...

        // 🟣 Karton Box
        Route::get('/template-karton-box', [StockAdjustmentController::class, 'downloadTemplateKartonBox']);
        Route::post('/upload-karton-box', [StockAdjustmentController::class, 'uploadSaldoAwalKartonBox']);
        
        // 🟡 Komponen
        Route::get('/template-komponen', [StockAdjustmentController::class, 'downloadTemplateKomponen']);
        Route::post('/upload-komponen', [StockAdjustmentController::class, 'uploadSaldoAwalKomponen']);
    });

    // --- PEMBELIAN ---
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::apiResource('purchase-orders', PurchaseOrderController::class)->except(['show']);

    Route::get('goods-receipts/unbilled', [GoodsReceiptController::class, 'getUnbilledReceipts']);
    Route::apiResource('goods-receipts', GoodsReceiptController::class);
    Route::apiResource('purchase-bills', PurchaseBillController::class);

    // --- PENJUALAN ---
    Route::apiResource('sales-orders', SalesOrderController::class);
    Route::get('sales-orders-open', [SalesOrderController::class, 'getOpenSalesOrders']);

    // --- PENGIRIMAN ---
    Route::apiResource('delivery-orders', DeliveryOrderController::class);

    // --- PRODUKSI / BOM ---
    Route::post('/boms/{bom}/execute-production', [BomController::class, 'executeProduction']);
    Route::post('/productions/transformation', [ProductionController::class, 'storeTransformation']);
    Route::post('/productions/mutation', [ProductionController::class, 'storeMutation']);
    Route::post('/candy-productions', [CandyProductionController::class, 'store']);
    Route::post('/sales-orders/{salesOrder}/production-orders', [ProductionOrderController::class, 'storeFromSalesOrder']);
    Route::get('/production-orders', [ProductionOrderController::class, 'index']);
    Route::get('/production-orders/{productionOrder}', [ProductionOrderController::class, 'show']);
    // --- GUDANG ---
    Route::get('/warehouses', [WarehouseController::class, 'index']);
    Route::get('/inventories', [InventoryController::class, 'index']);

    // --- PRODUKSI PENGGERGAJAN ---
    Route::post('/sawmill-productions', [SawmillProductionController::class, 'store']);

    // --- UTILITAS DASHBOARD (INI MASIH DIPAKAI) ---
    Route::get('/dashboard-route', function (Request $request) {
        $user = $request->user();
        $roles = $user->roles->pluck('name')->toArray();
        $dashboardRoute = '/dashboard';

        if (in_array('super-admin', $roles) || in_array('admin', $roles)) {
            $dashboardRoute = '/admin';
        } elseif (in_array('manager', $roles)) {
            $dashboardRoute = '/manager/dashboard';
        }

        return response()->json([
            'success' => true,
            'dashboard_route' => $dashboardRoute,
            'user_roles' => $roles
        ]);
    });
});
