<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_details', function (Blueprint $table) {
            $table->decimal('initial_stock_snapshot', 15, 4)->nullable()->after('qty_produced');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_details', function (Blueprint $table) {
            $table->dropColumn('initial_stock_snapshot');
        });
    }
};
