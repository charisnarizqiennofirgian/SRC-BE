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
    Schema::create('production_orders', function (Blueprint $table) {
        $table->id();
        $table->string('po_number')->unique();
        $table->foreignId('sales_order_id')->constrained()->onDelete('cascade');
        $table->string('status')->default('draft'); // draft, released, closed
        $table->text('notes')->nullable();
        $table->foreignId('created_by')->constrained('users');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
