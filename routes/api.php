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
use App\Http\Controllers\Api\PembahananController;
use App\Http\Controllers\Api\MouldingController;
use App\Http\Controllers\Api\ProductBomController;
use App\Http\Controllers\Api\OperatorMesinController;
use App\Http\Controllers\Api\AssemblingController;
use App\Http\Controllers\Api\SandingController;
use App\Http\Controllers\Api\RustikController;
use App\Http\Controllers\Api\FinishingController;
use App\Http\Controllers\Api\PackingController;
use App\Http\Controllers\Api\MaterialUsageController;
use App\Http\Controllers\Api\InventoryLogController;
use App\Http\Controllers\Api\ProductionMonitoringController;
use App\Http\Controllers\Api\ChartOfAccountController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\PurchasePaymentController;
use App\Http\Controllers\Api\SalesInvoiceController;
use App\Http\Controllers\Api\DownPaymentController;
use App\Http\Controllers\Api\InvoicePaymentController;
use App\Http\Controllers\Api\GeneralLedgerController;
use App\Http\Controllers\Api\IncomeStatementController;
use App\Http\Controllers\Api\BalanceSheetController;
use App\Http\Controllers\Api\PermissionController;


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
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')
        ];
    });

    // --- PENGATURAN ---
    // Roles Management
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index']);
        Route::post('/', [RoleController::class, 'store']);
        Route::get('/{id}', [RoleController::class, 'show']);
        Route::put('/{id}', [RoleController::class, 'update']);
        Route::delete('/{id}', [RoleController::class, 'destroy']);
    });

    // Permissions Management
    Route::prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index']);
        Route::post('/', [PermissionController::class, 'store']);
        Route::get('/{id}', [PermissionController::class, 'show']);
        Route::put('/{id}', [PermissionController::class, 'update']);
        Route::delete('/{id}', [PermissionController::class, 'destroy']);
    });

    // Get all permissions for role form
    Route::get('/roles-permissions', [RoleController::class, 'getPermissions']);

    // Users Management
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
        Route::post('/{id}/reset-password', [UserController::class, 'resetPassword']);
    });

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

    // BOM Produk
    Route::get('/stock-adjustments/template-bom', [StockAdjustmentController::class, 'downloadTemplateBom']);
    Route::post('/stock-adjustments/upload-bom', [StockAdjustmentController::class, 'uploadBom']);

    Route::prefix('stock-adjustments')->group(function () {
        // 🟣 Karton Box
        Route::get('/template-karton-box', [StockAdjustmentController::class, 'downloadTemplateKartonBox']);
        Route::post('/upload-karton-box', [StockAdjustmentController::class, 'uploadSaldoAwalKartonBox']);

        // 🟡 Komponen
        Route::get('/template-komponen', [StockAdjustmentController::class, 'downloadTemplateKomponen']);
        Route::post('/upload-komponen', [StockAdjustmentController::class, 'uploadSaldoAwalKomponen']);
    });

    // --- PEMBELIAN ---
    Route::get('purchase-orders/laporan-harga', [PurchaseOrderController::class, 'laporanHarga']);
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::apiResource('purchase-orders', PurchaseOrderController::class)->except(['show']);

    Route::get('goods-receipts/unbilled', [GoodsReceiptController::class, 'getUnbilledReceipts']);
    Route::apiResource('goods-receipts', GoodsReceiptController::class);
    Route::get('purchase-bills/form-data', [PurchaseBillController::class, 'getFormData']);
    Route::apiResource('purchase-bills', PurchaseBillController::class);

    // --- PEMBAYARAN HUTANG ---
    Route::prefix('purchase-payments')->group(function () {
        Route::get('/', [PurchasePaymentController::class, 'index']);
        Route::post('/', [PurchasePaymentController::class, 'store']);
        Route::get('/form-data', [PurchasePaymentController::class, 'getFormData']);
        Route::get('/outstanding-bills', [PurchasePaymentController::class, 'getOutstandingBills']);
        Route::get('/{id}', [PurchasePaymentController::class, 'show']);
    });

    // --- PENJUALAN ---
    Route::apiResource('sales-orders', SalesOrderController::class);
    Route::get('sales-orders-open', [SalesOrderController::class, 'getOpenSalesOrders']);

    // --- PENGIRIMAN ---
    Route::prefix('delivery-orders')->group(function () {
        Route::get('/available-for-invoice', [DeliveryOrderController::class, 'getAvailableForInvoice']);
        Route::get('/', [DeliveryOrderController::class, 'index']);
        Route::post('/', [DeliveryOrderController::class, 'store']);
        Route::get('/{delivery_order}', [DeliveryOrderController::class, 'show']);
        Route::put('/{delivery_order}', [DeliveryOrderController::class, 'update']);
        Route::delete('/{delivery_order}', [DeliveryOrderController::class, 'destroy']);
        Route::post('/{id}/ship', [DeliveryOrderController::class, 'ship']);
        Route::post('/{id}/mark-delivered', [DeliveryOrderController::class, 'markDelivered']);
    });

    // --- SALES INVOICE ---
    Route::prefix('sales-invoices')->group(function () {
        Route::get('/', [SalesInvoiceController::class, 'index']);
        Route::post('/', [SalesInvoiceController::class, 'store']);
        Route::get('/available-delivery-orders', [SalesInvoiceController::class, 'getAvailableDeliveryOrders']);
        Route::get('/{id}', [SalesInvoiceController::class, 'show']);
        Route::get('/{id}/down-payments', [InvoicePaymentController::class, 'getAvailableDownPayments']);
        Route::post('/{id}/post', [SalesInvoiceController::class, 'post']);
        Route::post('/{id}/cancel', [SalesInvoiceController::class, 'cancel']);
        Route::delete('/{id}', [SalesInvoiceController::class, 'destroy']);
    });

    // --- UANG MUKA PENJUALAN ---
    Route::prefix('down-payments')->group(function () {
        Route::get('/', [DownPaymentController::class, 'index']);
        Route::post('/', [DownPaymentController::class, 'store']);
        Route::get('/available/{buyerId}', [DownPaymentController::class, 'getAvailable']);
        Route::get('/{id}', [DownPaymentController::class, 'show']);
        Route::delete('/{id}', [DownPaymentController::class, 'destroy']);
    });

    // --- PEMBAYARAN FAKTUR ---
    Route::prefix('invoice-payments')->group(function () {
        Route::get('/', [InvoicePaymentController::class, 'index']);
        Route::post('/cash', [InvoicePaymentController::class, 'receiveCashPayment']);
        Route::post('/down-payment', [InvoicePaymentController::class, 'receiveDownPaymentDeduction']);
        Route::get('/{id}', [InvoicePaymentController::class, 'show']);
        Route::delete('/{id}', [InvoicePaymentController::class, 'destroy']);
    });

    // --- PRODUKSI / BOM ---
    Route::post('/boms/{bom}/execute-production', [BomController::class, 'executeProduction']);
    Route::post('/productions/transformation', [ProductionController::class, 'storeTransformation']);
    Route::post('/productions/mutation', [ProductionController::class, 'storeMutation']);
    Route::post('/candy-productions', [CandyProductionController::class, 'store']);
    Route::post('/sales-orders/{salesOrder}/production-orders', [ProductionOrderController::class, 'storeFromSalesOrder']);
    Route::get('/production-orders', [ProductionOrderController::class, 'index']);
    Route::get('/production-orders/simple', [ProductionOrderController::class, 'simpleList']);
    Route::get('/production-orders/{productionOrder}', [ProductionOrderController::class, 'show']);
    Route::post('/product-boms/import', [ProductBomController::class, 'import']);

    // PRODUCTION BOM
    Route::prefix('production')->group(function () {
        Route::get('/bom', [ProductBomController::class, 'index']);
        Route::post('/bom/import', [ProductBomController::class, 'import']);
    });

    // --- PROSES PEMBAHANAN ---
    Route::get('/produksi/pembahanan/available-pos', [PembahananController::class, 'getAvailableProductionOrders']);
    Route::get('/produksi/pembahanan/source-inventories', [PembahananController::class, 'sourceInventories']);
    Route::post('/produksi/pembahanan', [PembahananController::class, 'store']);

    // --- PROSES MOULDING ---
    Route::get('produksi/moulding/source-inventories', [MouldingController::class, 'sourceInventories']);
    Route::post('produksi/moulding', [MouldingController::class, 'store']);

    // --- OPERATOR MESIN ---
    Route::post('/operator-mesin/produce', [OperatorMesinController::class, 'produce']);
    Route::post('/operator-mesin/recipe', [OperatorMesinController::class, 'storeRecipe']);
    Route::get('/operator-mesin/po/{id}', [OperatorMesinController::class, 'showByPo']);

    // --- GUDANG ---
    Route::get('/warehouses', [WarehouseController::class, 'index']);
    Route::get('/inventories', [InventoryController::class, 'index']);

    // --- PRODUKSI PENGGERGAJAN ---
    Route::post('/sawmill-productions', [SawmillProductionController::class, 'store']);

    // --- PROSES ASSEMBLING ---
    Route::prefix('assembling')->group(function () {
        Route::get('/orders', [AssemblingController::class, 'getAvailableOrders']);
        Route::post('/check-material', [AssemblingController::class, 'checkMaterialAvailability']);
        Route::post('/store', [AssemblingController::class, 'store']);
    });

    // --- PROSES SANDING ---
    Route::prefix('sanding')->group(function () {
        Route::get('/available-stock', [SandingController::class, 'getAvailableStock']);
        Route::post('/process', [SandingController::class, 'process']);
    });

    // --- PROSES RUSTIK ---
    Route::prefix('rustik')->group(function () {
        Route::get('/available-stock', [RustikController::class, 'getAvailableStock']);
        Route::post('/process', [RustikController::class, 'process']);
    });

    // --- PROSES FINISHING ---
    Route::prefix('finishing')->group(function () {
        Route::get('/available-stock', [FinishingController::class, 'getAvailableStock']);
        Route::post('/process', [FinishingController::class, 'process']);
    });

    // --- PROSES PACKING ---
    Route::prefix('packing')->group(function () {
        Route::get('/available-stock', [PackingController::class, 'getAvailableStock']);
        Route::post('/process', [PackingController::class, 'process']);
    });

    // --- PENGGUNAAN MATERIAL (ASSEMBLING DLL) ---
    Route::prefix('material-usages')->group(function () {
        Route::get('/', [MaterialUsageController::class, 'index']);
        Route::post('/', [MaterialUsageController::class, 'store']);
        Route::get('/consumables', [MaterialUsageController::class, 'getConsumableItems']);
        Route::get('/categories', [MaterialUsageController::class, 'getConsumableCategories']);
        Route::get('/divisions', [MaterialUsageController::class, 'getDivisions']);
        Route::get('/stock/{itemId}', [MaterialUsageController::class, 'checkStock']);
    });

    // --- LOG INVENTORY ---
    Route::prefix('inventory-logs')->group(function () {
        Route::get('/', [InventoryLogController::class, 'index']);
        Route::get('/warehouses', [InventoryLogController::class, 'getWarehouses']);
        Route::get('/transaction-types', [InventoryLogController::class, 'getTransactionTypes']);
        Route::get('/items', [InventoryLogController::class, 'getItems']);
    });

    // --- MONITORING PRODUKSI ---
    Route::prefix('production-monitoring')->group(function () {
        Route::get('/', [ProductionMonitoringController::class, 'index']);
    });

    // --- CHART OF ACCOUNT (AKUN PERKIRAAN) ---
    Route::prefix('coa')->group(function () {
        Route::get('/all', [ChartOfAccountController::class, 'all']);
        Route::get('/', [ChartOfAccountController::class, 'index']);
        Route::post('/', [ChartOfAccountController::class, 'store']);
        Route::get('/types', [ChartOfAccountController::class, 'getTypes']);
        Route::get('/by-type/{type}', [ChartOfAccountController::class, 'getByType']);
        Route::get('/template', [ChartOfAccountController::class, 'downloadTemplate']);
        Route::post('/import', [ChartOfAccountController::class, 'import']);
        Route::get('/export', [ChartOfAccountController::class, 'export']);
        Route::get('/{id}', [ChartOfAccountController::class, 'show']);
        Route::put('/{id}', [ChartOfAccountController::class, 'update']);
        Route::delete('/{id}', [ChartOfAccountController::class, 'destroy']);
    });

    // --- METODE PEMBAYARAN ---
    Route::prefix('payment-methods')->group(function () {
        Route::get('/', [PaymentMethodController::class, 'index']);
        Route::post('/', [PaymentMethodController::class, 'store']);
        Route::get('/types', [PaymentMethodController::class, 'getTypes']);
        Route::get('/active', [PaymentMethodController::class, 'getActive']);
        Route::get('/{id}', [PaymentMethodController::class, 'show']);
        Route::put('/{id}', [PaymentMethodController::class, 'update']);
        Route::delete('/{id}', [PaymentMethodController::class, 'destroy']);
    });

    // --- JURNAL UMUM ---
    Route::prefix('journal-entries')->group(function () {
        Route::get('/', [JournalEntryController::class, 'index']);
        Route::post('/manual', [JournalEntryController::class, 'storeManual']);
        Route::get('/{id}', [JournalEntryController::class, 'show']);

        // ✅ TAMBAH ROUTES BARU
        Route::post('/{id}/unpost', [JournalEntryController::class, 'unpost']);
        Route::post('/{id}/repost', [JournalEntryController::class, 'repost']);
        Route::post('/{id}/void', [JournalEntryController::class, 'void']);
        Route::put('/{id}/update-manual', [JournalEntryController::class, 'updateManual']);
        Route::get('/{id}/history', [JournalEntryController::class, 'history']);
    });

    // --- BUKU BESAR (GENERAL LEDGER) ---
    Route::get('general-ledger', [GeneralLedgerController::class, 'index']);

    // --- LAPORAN LABA RUGI (INCOME STATEMENT) ---
    Route::get('income-statement', [IncomeStatementController::class, 'index']);

    // --- NERACA (BALANCE SHEET) ---
    Route::get('balance-sheet', [BalanceSheetController::class, 'index']);

    // --- UTILITAS DASHBOARD ---
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
