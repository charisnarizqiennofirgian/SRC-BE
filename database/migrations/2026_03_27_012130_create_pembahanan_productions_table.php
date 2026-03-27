<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pembahanan_productions', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->date('date');
            $table->date('estimated_finish_date')->nullable();
            $table->unsignedBigInteger('ref_po_id');
            $table->unsignedBigInteger('source_warehouse_id'); // KD atau Sanwil
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('ref_po_id')
                  ->references('id')->on('production_orders')
                  ->onDelete('cascade');
            $table->foreign('source_warehouse_id')
                  ->references('id')->on('warehouses')
                  ->onDelete('cascade');
            $table->foreign('created_by')
                  ->references('id')->on('users')
                  ->onDelete('set null');
        });

        Schema::create('pembahanan_production_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pembahanan_production_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('qty', 15, 4);
            $table->timestamps();

            $table->foreign('pembahanan_production_id')
                  ->references('id')->on('pembahanan_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembahanan_production_items');
        Schema::dropIfExists('pembahanan_productions');
    }
};