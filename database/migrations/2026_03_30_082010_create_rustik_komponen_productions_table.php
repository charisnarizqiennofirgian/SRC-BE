<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Header transaksi
        Schema::create('rustik_komponen_productions', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->date('date');
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

        // Input: komponen dari Gudang Mesin
        Schema::create('rustik_komponen_inputs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rustik_komponen_production_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('qty', 15, 4);
            $table->timestamps();

            $table->foreign('rustik_komponen_production_id')
                  ->references('id')->on('rustik_komponen_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
        });

        // Output: hasil → Gudang Rustik Komponen
        Schema::create('rustik_komponen_outputs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rustik_komponen_production_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('qty', 15, 4);
            $table->timestamps();

            $table->foreign('rustik_komponen_production_id')
                  ->references('id')->on('rustik_komponen_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
        });

        // Reject → Gudang Reject
        Schema::create('rustik_komponen_rejects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rustik_komponen_production_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('qty', 15, 4);
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('rustik_komponen_production_id')
                  ->references('id')->on('rustik_komponen_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rustik_komponen_rejects');
        Schema::dropIfExists('rustik_komponen_outputs');
        Schema::dropIfExists('rustik_komponen_inputs');
        Schema::dropIfExists('rustik_komponen_productions');
    }
};