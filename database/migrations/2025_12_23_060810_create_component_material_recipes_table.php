<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('component_material_recipes', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('component_item_id');
    $table->unsignedBigInteger('material_item_id');
    $table->decimal('qty_per_unit', 12, 4);
    $table->timestamps();

    $table->foreign('component_item_id')->references('id')->on('items');
    $table->foreign('material_item_id')->references('id')->on('items');
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('component_material_recipes');
    }
};
