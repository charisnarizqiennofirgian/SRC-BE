<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoice_details', function (Blueprint $table) {
            $table->id();

            // Relasi
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->onDelete('cascade');
            $table->foreignId('sales_order_detail_id')->constrained('sales_order_details');
            $table->foreignId('delivery_order_detail_id')->nullable()->constrained('delivery_order_details');
            $table->foreignId('item_id')->constrained('items');

            // Info Item
            $table->string('item_name');
            $table->string('item_code')->nullable();
            $table->string('item_unit');

            // Quantity
            $table->decimal('quantity', 15, 2);

            // Harga dalam currency asli
            $table->decimal('unit_price_original', 15, 2);
            $table->decimal('discount_original', 15, 2)->default(0);
            $table->decimal('subtotal_original', 15, 2);

            // Harga dalam IDR (untuk jurnal)
            $table->decimal('unit_price_idr', 15, 2);
            $table->decimal('discount_idr', 15, 2)->default(0);
            $table->decimal('subtotal_idr', 15, 2);

            // HPP untuk jurnal (opsional, bisa ditambah nanti)
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->decimal('total_cost', 15, 2)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_details');
    }
};
