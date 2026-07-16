<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moulding_productions', function (Blueprint $table) {
            $table->decimal('qty_produk_jadi', 15, 4)->nullable()->after('production_order_detail_id');
        });

        Schema::table('mesin_productions', function (Blueprint $table) {
            $table->decimal('qty_produk_jadi', 15, 4)->nullable()->after('production_order_detail_id');
        });
    }

    public function down(): void
    {
        Schema::table('moulding_productions', function (Blueprint $table) {
            $table->dropColumn('qty_produk_jadi');
        });

        Schema::table('mesin_productions', function (Blueprint $table) {
            $table->dropColumn('qty_produk_jadi');
        });
    }
};
