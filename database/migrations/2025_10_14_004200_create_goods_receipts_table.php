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
    Schema::create('goods_receipts', function (Blueprint $table) {
        $table->id();
        $table->string('receipt_number')->unique();
        $table->foreignId('purchase_order_id')->constrained('purchase_orders');
        $table->foreignId('item_id')->constrained('items');
        $table->date('receipt_date');
        $table->decimal('quantity_received', 15, 4);
        $table->text('notes')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
