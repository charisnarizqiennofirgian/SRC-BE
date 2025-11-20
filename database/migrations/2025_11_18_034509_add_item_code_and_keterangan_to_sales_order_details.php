<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_order_details', function (Blueprint $table) {
            // Tambah kolom item_code setelah item_id
            $table->string('item_code', 50)->nullable()->after('item_id');
            
            // Tambah kolom keterangan setelah delivery_date
            $table->text('keterangan')->nullable()->after('delivery_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_order_details', function (Blueprint $table) {
            $table->dropColumn(['item_code', 'keterangan']);
        });
    }
};
