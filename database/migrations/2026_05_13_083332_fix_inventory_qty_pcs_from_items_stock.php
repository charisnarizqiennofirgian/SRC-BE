<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix inventories.qty_pcs yang 0 akibat bug penggunaan virtual attribute 'qty'
        // di Inventory::updateOrCreate. items.stock adalah nilai aktual karena semua
        // transaksi (GoodsReceipt, MaterialUsage, Production, StockAdjustment) pakai items.stock.
        DB::statement('
            UPDATE inventories i
            JOIN items it ON it.id = i.item_id
            SET i.qty_pcs = it.stock
            WHERE i.qty_pcs = 0 AND it.stock > 0
        ');
    }

    public function down(): void
    {
        // Tidak dapat di-rollback secara otomatis karena tidak tahu nilai asli qty_pcs = 0
        // yang memang 0 vs yang ter-bug.
    }
};
