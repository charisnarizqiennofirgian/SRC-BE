<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('so_number')->unique();
            $table->foreignId('buyer_id')->constrained('buyers');
            $table->foreignId('user_id')->constrained('users');
            
            $table->date('so_date');
            $table->date('delivery_date')->nullable();
            $table->string('customer_po_number')->nullable();
            
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax_ppn', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);

            $table->string('currency', 3)->default('IDR');
            $table->decimal('exchange_rate', 15, 4)->default(1);

            $table->text('notes')->nullable();
            $table->string('status')->default('Draft');
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};