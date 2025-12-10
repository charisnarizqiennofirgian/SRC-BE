<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStocksTable extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained()->onDelete('cascade');
            $table->decimal('quantity', 15, 4)->default(0); // bisa dipakai untuk pcs atau m3
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id']); // stok unik per item+gudang
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
}
