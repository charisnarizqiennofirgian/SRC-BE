<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rustik_komponen_productions', function (Blueprint $table) {
            $table->foreignId('production_order_detail_id')
                ->nullable()
                ->after('ref_po_id')
                ->constrained('production_order_details')
                ->nullOnDelete();
            $table->decimal('qty_produk_jadi', 15, 4)->nullable()->after('production_order_detail_id');
        });
    }

    public function down(): void
    {
        Schema::table('rustik_komponen_productions', function (Blueprint $table) {
            $table->dropForeign(['production_order_detail_id']);
            $table->dropColumn(['production_order_detail_id', 'qty_produk_jadi']);
        });
    }
};
