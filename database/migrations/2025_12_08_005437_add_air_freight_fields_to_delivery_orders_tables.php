<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            // ✅ Mode pengiriman: SEA / AIR
            $table->string('shipment_mode', 10)
                  ->default('SEA')
                  ->after('status');
        });

        Schema::table('delivery_order_details', function (Blueprint $table) {
            // ✅ Jumlah crate per item (hanya dipakai AIR)
            $table->integer('quantity_crates')
                  ->nullable()
                  ->after('quantity_boxes');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn('shipment_mode');
        });

        Schema::table('delivery_order_details', function (Blueprint $table) {
            $table->dropColumn('quantity_crates');
        });
    }
};
