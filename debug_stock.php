<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Item;
use App\Models\Category;
use Illuminate\Http\Request;

// Simulate the logic in StockReportController
$itemName = '23-8467'; // The code from user

$item = Item::where('code', $itemName)
    ->with([
        'inventories:id,item_id,warehouse_id,qty_pcs,qty_m3',
    ])
    ->first();

if (!$item) {
    echo "Item not found\n";
    exit;
}

echo "Item ID: " . $item->id . "\n";
echo "Item Code: " . $item->code . "\n";
echo "Item Stock (Direct): " . $item->stock . "\n";

$inventories = $item->inventories;
echo "Inventories Count: " . $inventories->count() . "\n";

foreach ($inventories as $inv) {
    echo "  Inv ID: " . $inv->id . ", Qty PCS: " . $inv->qty_pcs . ", Qty M3: " . $inv->qty_m3 . "\n";
}

$totalFromStocks = $inventories->sum(function ($inv) {
    return (float) ($inv->qty_pcs ?? 0);
});

echo "Total From Stocks (Calculated): " . $totalFromStocks . "\n";

if ($totalFromStocks == 0) {
    $totalFromStocks = (float) ($item->stock ?? 0);
    echo "Fallback to Item Stock: " . $totalFromStocks . "\n";
}

$item->total_stock_from_stocks = $totalFromStocks;
echo "Final Total Stock: " . $item->total_stock_from_stocks . "\n";

echo "JSON Serialize:\n";
echo json_encode($item, JSON_PRETTY_PRINT);
