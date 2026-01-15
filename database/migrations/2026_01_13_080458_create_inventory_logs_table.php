<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_logs', function (Blueprint $table) {
            $table->id();

            // === WAKTU TRANSAKSI ===
            $table->date('date');                    // Tanggal transaksi
            $table->time('time')->nullable();        // Jam transaksi (boleh kosong)

            // === ITEM & GUDANG ===
            $table->foreignId('item_id')             // ID barang yang bergerak
                  ->constrained('items')
                  ->onDelete('cascade');
            $table->foreignId('warehouse_id')        // ID gudang
                  ->constrained('warehouses')
                  ->onDelete('cascade');

            // === JUMLAH ===
            $table->decimal('qty', 15, 4);           // Jumlah barang
            $table->decimal('qty_m3', 15, 6)->default(0);  // Kubikasi (khusus kayu)

            // === ARAH: MASUK atau KELUAR ===
            $table->enum('direction', ['IN', 'OUT']); // IN = masuk, OUT = keluar

            // === TIPE TRANSAKSI ===
            $table->string('transaction_type', 50);  // PURCHASE, SALE, USAGE, dll

            // === REFERENSI DOKUMEN ===
            $table->string('reference_type')->nullable();    // Nama model (GoodsReceipt, dll)
            $table->unsignedBigInteger('reference_id')->nullable();  // ID dokumen
            $table->string('reference_number')->nullable();  // Nomor dokumen (PO-2026-001)

            // === DETAIL TAMBAHAN ===
            $table->string('division')->nullable();  // Divisi (untuk pemakaian bahan)
            $table->text('notes')->nullable();       // Catatan/keterangan

            // === SIAPA YANG INPUT ===
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');

            $table->timestamps();

            // === INDEX (biar query cepat) ===
            $table->index(['date', 'warehouse_id']);
            $table->index(['item_id', 'date']);
            $table->index(['transaction_type', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_logs');
    }
};
