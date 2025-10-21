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
    Schema::create('purchase_orders', function (Blueprint $table) {
        $table->id();
        $table->string('po_number')->unique();
        $table->foreignId('supplier_id')->constrained('suppliers');
        $table->date('order_date');
        $table->date('expected_delivery_date')->nullable();
        $table->string('status')->default('Draf');
        
        
        $table->decimal('subtotal', 15, 2)->default(0);
        $table->decimal('ppn_percentage', 5, 2)->default(0); 
        $table->decimal('ppn_amount', 15, 2)->default(0);
        $table->decimal('grand_total', 15, 2)->default(0);
        

        $table->text('notes')->nullable();
        $table->timestamps();
        $table->softDeletes(); 
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
