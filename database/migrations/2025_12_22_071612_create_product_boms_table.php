<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('product_boms', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('parent_item_id'); // FG / produk
        $table->unsignedBigInteger('child_item_id');  // komponen
        $table->decimal('qty', 15, 4);                // kebutuhan per 1 parent
        $table->timestamps();

        $table->foreign('parent_item_id')->references('id')->on('items')->cascadeOnDelete();
        $table->foreign('child_item_id')->references('id')->on('items')->cascadeOnDelete();
        $table->unique(['parent_item_id', 'child_item_id']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_boms');
    }
};
