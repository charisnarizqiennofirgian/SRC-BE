<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moulding_productions', function (Blueprint $table) {
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

        // Input: RST yang dipakai (dari master Kayu RST)
        Schema::create('moulding_production_inputs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('moulding_production_id');
            $table->unsignedBigInteger('item_id'); // item RST
            $table->decimal('qty', 15, 4);
            $table->timestamps();

            $table->foreign('moulding_production_id')
                  ->references('id')->on('moulding_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
        });

        // Output: Komponen yang dihasilkan → Gudang Mesin
        Schema::create('moulding_production_outputs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('moulding_production_id');
            $table->unsignedBigInteger('item_id'); // item komponen
            $table->decimal('qty', 15, 4);
            $table->timestamps();

            $table->foreign('moulding_production_id')
                  ->references('id')->on('moulding_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
        });

        // Reject: Komponen gagal → Gudang Reject
        Schema::create('moulding_production_rejects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('moulding_production_id');
            $table->unsignedBigInteger('item_id'); // item yang reject
            $table->decimal('qty', 15, 4);
            $table->enum('reject_type', ['moulding', 'pembahanan'])->default('moulding');
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('moulding_production_id')
                  ->references('id')->on('moulding_productions')
                  ->onDelete('cascade');
            $table->foreign('item_id')
                  ->references('id')->on('items')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moulding_production_rejects');
        Schema::dropIfExists('moulding_production_outputs');
        Schema::dropIfExists('moulding_production_inputs');
        Schema::dropIfExists('moulding_productions');
    }
};