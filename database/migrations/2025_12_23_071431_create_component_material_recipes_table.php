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
    Schema::create('component_material_recipes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('component_item_id')->constrained('items')->onDelete('cascade');
        $table->foreignId('material_item_id')->constrained('items')->onDelete('cascade');
        $table->decimal('qty_per_unit', 10, 3); // misal 0.200
        $table->timestamps();
        
        $table->unique(['component_item_id', 'material_item_id']); // 1 komponen = 1 kayu aja
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
