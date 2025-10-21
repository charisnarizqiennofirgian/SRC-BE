<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique(); 
            $table->foreignId('purchase_order_id')->constrained('purchase_orders');
            $table->date('receipt_date');
            $table->string('supplier_document_number')->nullable(); 
            $table->string('status')->default('Completed'); 
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};