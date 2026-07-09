<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu DO sekarang bisa menggabungkan beberapa Sales Order (dipilih di frontend saat
     * pengiriman gabungan). Sumber kebenaran pindah ke delivery_order_details ->
     * sales_order_detail_id -> sales_order_id. Kolom ini dipertahankan sebagai SO "utama"
     * (SO pertama yang dipilih) untuk kompatibilitas tampilan lama, tapi jadi nullable.
     */
    public function up(): void
    {
        Schema::table('delivery_orders', function ($table) {
            $table->dropForeign('delivery_orders_sales_order_id_foreign');
        });

        DB::statement('ALTER TABLE delivery_orders MODIFY sales_order_id BIGINT UNSIGNED NULL');

        Schema::table('delivery_orders', function ($table) {
            $table->foreign('sales_order_id')->references('id')->on('sales_orders');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function ($table) {
            $table->dropForeign('delivery_orders_sales_order_id_foreign');
        });

        DB::statement('ALTER TABLE delivery_orders MODIFY sales_order_id BIGINT UNSIGNED NOT NULL');

        Schema::table('delivery_orders', function ($table) {
            $table->foreign('sales_order_id')->references('id')->on('sales_orders');
        });
    }
};
