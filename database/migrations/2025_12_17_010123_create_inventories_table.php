<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('warehouse_id');          // gudang: Sanwil, Candy, dll
            $table->unsignedBigInteger('item_id');               // item: Log, RST, Komponen, Produk Jadi

            // Simpan dua satuan: pcs & m3
            $table->decimal('qty_pcs', 15, 4)->default(0);        // jumlah fisik (batang/pcs)
            $table->decimal('qty_m3', 15, 6)->default(0);         // kubikasi total

            // Identitas pemilik stok
            $table->string('ref_po_id')->nullable();             // contoh: PO-RIZAL-001
            $table->unsignedBigInteger('ref_product_id')->nullable(); // id produk akhir (meja bella)

            $table->timestamps();

            // index supaya query cepat
            $table->index(['warehouse_id', 'item_id']);
            $table->index(['ref_po_id', 'ref_product_id']);
            $table->index(['warehouse_id', 'item_id', 'ref_po_id', 'ref_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
