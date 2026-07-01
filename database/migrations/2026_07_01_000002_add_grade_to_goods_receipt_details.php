<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_details', function (Blueprint $table) {
            $table->string('grade', 50)->nullable()->after('subtotal');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->string('grade', 50)->nullable()->after('qty_m3');
            $table->index(['warehouse_id', 'item_id', 'grade']);
        });

        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->string('grade', 50)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_details', function (Blueprint $table) {
            $table->dropColumn('grade');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->dropIndex(['warehouse_id', 'item_id', 'grade']);
            $table->dropColumn('grade');
        });

        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropColumn('grade');
        });
    }
};
