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
    Schema::table('purchase_order_details', function (Blueprint $table) {
        $table->date('delivery_date')->nullable()->after('subtotal');
    });

    Schema::table('purchase_orders', function (Blueprint $table) {
        $table->string('no_surat_jalan')->nullable()->after('delivery_date');
    });
}

public function down(): void
{
    Schema::table('purchase_order_details', function (Blueprint $table) {
        $table->dropColumn('delivery_date');
    });

    Schema::table('purchase_orders', function (Blueprint $table) {
        $table->dropColumn('no_surat_jalan');
    });
}
};
