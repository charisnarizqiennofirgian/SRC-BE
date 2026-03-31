<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Header — berlaku untuk Sub Assembling & Rakit
        Schema::create('assembling_productions', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->date('date');
            $table->enum('process_type', ['sub_assembling', 'rakit']);
            $table->unsignedBigInteger('ref_po_id');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('ref_po_id')
                  ->references('id')->on('production_orders')
                  ->onDelete('cascade');
            $table->foreign('created_by')
                  ->references('id')->on('users')
                  ->onDelete('set null');
        });

        // Input: komponen yang dipakai
        Schema::create('assembling_production_inputs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assembling_production_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('warehouse_id'); // dari gudang mana
            $table->decimal('qty', 15, 4);
            $table->timestamps();

            $table->foreign('assembling_production_id')
                  ->references('id')->on('assembling_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
            $table->foreign('warehouse_id')
                  ->references('id')->on('warehouses')
                  ->onDelete('cascade');
        });

        // Output: hasil → Gudang Assembling
        Schema::create('assembling_production_outputs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assembling_production_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('qty', 15, 4);
            $table->timestamps();

            $table->foreign('assembling_production_id')
                  ->references('id')->on('assembling_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
        });

        // Reject → Gudang Reject
        Schema::create('assembling_production_rejects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assembling_production_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('qty', 15, 4);
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('assembling_production_id')
                  ->references('id')->on('assembling_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assembling_production_rejects');
        Schema::dropIfExists('assembling_production_outputs');
        Schema::dropIfExists('assembling_production_inputs');
        Schema::dropIfExists('assembling_productions');
    }
};