<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_final_productions', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->date('date');
            $table->unsignedBigInteger('ref_po_id');
            $table->unsignedBigInteger('source_warehouse_id');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('ref_po_id')->references('id')->on('production_orders')->onDelete('cascade');
            $table->foreign('source_warehouse_id')->references('id')->on('warehouses')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // Item yang lolos QC → Gudang Packing
        Schema::create('qc_final_passed_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('qc_final_production_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('qty', 15, 4);
            $table->timestamps();

            $table->foreign('qc_final_production_id')->references('id')->on('qc_final_productions')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('items')->onDelete('cascade');
        });

        // Item yang reject → Gudang Reject
        Schema::create('qc_final_reject_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('qc_final_production_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('qty', 15, 4);
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('qc_final_production_id')->references('id')->on('qc_final_productions')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('items')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_final_reject_items');
        Schema::dropIfExists('qc_final_passed_items');
        Schema::dropIfExists('qc_final_productions');
    }
};