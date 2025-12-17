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
    Schema::create('production_order_details', function (Blueprint $table) {
        $table->id();
        $table->foreignId('production_order_id')
              ->constrained('production_orders')
              ->onDelete('cascade');
        $table->foreignId('sales_order_detail_id')
              ->constrained('sales_order_details')
              ->onDelete('cascade');
        $table->foreignId('item_id')
              ->constrained('items');
        $table->decimal('qty_planned', 12, 3);
        $table->decimal('qty_produced', 12, 3)->default(0);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_order_details');
    }
};
