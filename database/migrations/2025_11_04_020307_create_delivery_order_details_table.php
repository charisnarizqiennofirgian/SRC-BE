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
        Schema::create('delivery_order_details', function (Blueprint $table) {
            $table->id();

            
            $table->foreignId('delivery_order_id')->constrained('delivery_orders')->onDelete('cascade');

            
            $table->foreignId('sales_order_detail_id')->constrained('sales_order_details');

           
            $table->foreignId('item_id')->constrained('items');
            $table->string('item_name');
            $table->string('item_unit');

           
            $table->decimal('quantity_shipped', 15, 4);

            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_order_details');
    }
};