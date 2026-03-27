<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master Mesin
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('name');        // WBS, Corcat, Tenon, dll
            $table->string('code')->unique(); // WBS, CRC, TNO, dll
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Header transaksi proses mesin
        Schema::create('mesin_productions', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->date('date');
            $table->unsignedBigInteger('ref_po_id');
            $table->unsignedBigInteger('machine_id'); // mesin yang dipakai
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('ref_po_id')
                  ->references('id')->on('production_orders')
                  ->onDelete('cascade');
            $table->foreign('machine_id')
                  ->references('id')->on('machines')
                  ->onDelete('cascade');
            $table->foreign('created_by')
                  ->references('id')->on('users')
                  ->onDelete('set null');
        });

        // Input: komponen dari Gudang S4S
        Schema::create('mesin_production_inputs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mesin_production_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('qty', 15, 4);
            $table->timestamps();

            $table->foreign('mesin_production_id')
                  ->references('id')->on('mesin_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
        });

        // Output: hasil komponen → Gudang Mesin
        Schema::create('mesin_production_outputs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mesin_production_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('qty', 15, 4);
            $table->timestamps();

            $table->foreign('mesin_production_id')
                  ->references('id')->on('mesin_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
        });

        // Reject: komponen gagal → Gudang Reject
        Schema::create('mesin_production_rejects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mesin_production_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('qty', 15, 4);
            $table->string('machine_id')->nullable(); // mesin mana yang reject
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('mesin_production_id')
                  ->references('id')->on('mesin_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesin_production_rejects');
        Schema::dropIfExists('mesin_production_outputs');
        Schema::dropIfExists('mesin_production_inputs');
        Schema::dropIfExists('mesin_productions');
        Schema::dropIfExists('machines');
    }
};