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

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Di sinilah kita akan mendaftarkan semua rute API untuk aplikasi kita.
|
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
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')
        ];
    });

    // --- PENGATURAN ---
    Route::apiResource('roles', RoleController::class)->only(['index', 'store']);
    Route::apiResource('users', UserController::class)->only(['store']);

    // --- MASTER DATA (SUDAH DIRAPIKAN & DISTANDARISASI) ---
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
    Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store']);
    Route::post('/stock-adjustments/upload', [StockAdjustmentController::class, 'upload']);

    // --- PEMBELIAN ---
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::apiResource('purchase-orders', PurchaseOrderController::class)->except(['show']);

    
    Route::get('goods-receipts/unbilled', [GoodsReceiptController::class, 'getUnbilledReceipts']);

    Route::apiResource('goods-receipts', GoodsReceiptController::class);
    Route::apiResource('purchase-bills', PurchaseBillController::class);

    // --- UTILITAS MENU & DASHBOARD ---
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

    Route::get('/user-menu', function (Request $request) {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'menu_items' => []], 401);
        }
        
        $roles = $user->roles->pluck('name')->toArray();
        $menuItems = [];

        // Semua role bisa melihat Dashboard
        $menuItems[] = ['name' => 'Dashboard', 'route' => '/dashboard', 'icon' => 'home'];

        // Hanya admin dan super-admin yang bisa melihat menu lainnya
        if (in_array('super-admin', $roles) || in_array('admin', $roles)) {
            
            // Grup Menu Master Data
            $menuItems[] = [
                'name' => 'Master Data', 
                'icon' => 'database',
                'children' => [
                    ['name' => 'Kategori Produk', 'route' => '/categories'],
                    ['name' => 'Satuan', 'route' => '/units'],
                    ['name' => 'Produk Jadi', 'route' => '/products'],
                    ['name' => 'Bahan Baku', 'route' => '/materials'],
                    ['name' => 'Supplier', 'route' => '/suppliers'],
                    ['name' => 'Buyer', 'route' => '/buyers'],
                ]
            ];

            // Grup Menu Manajemen Stok
            $menuItems[] = [
                'name' => 'Manajemen Stok',
                'icon' => 'box',
                'children' => [
                    ['name' => 'Laporan Stok', 'route' => '/stock-report'],
                    ['name' => 'Penyesuaian Stok', 'route' => '/stock-adjustment'],
                ]
            ];

            // Grup Menu Pembelian
            $menuItems[] = [
                'name' => 'Pembelian',
                'icon' => 'shopping-cart',
                'children' => [
                    ['name' => 'Pesanan Pembelian', 'route' => '/pembelian'],
                ]
            ];
            
            // Grup Menu Pengaturan
            $menuItems[] = [
                'name' => 'Pengaturan',
                'icon' => 'settings',
                'children' => [
                    ['name' => 'Manajemen User', 'route' => '/admin/users'],
                    ['name' => 'Manajemen Role', 'route' => '/admin/roles'],
                ]
            ];
        }
        
        return response()->json([
            'success' => true,
            'menu_items' => $menuItems
        ]);
    });
});