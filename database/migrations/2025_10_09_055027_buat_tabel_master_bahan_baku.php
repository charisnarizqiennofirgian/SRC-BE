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
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique(); // KOLOM CODE
            $table->string('name');                      // KOLOM NAME
            $table->text('description')->nullable();
            $table->enum('type', ['Stok', 'Non-Stok'])->default('Stok'); // KOLOM TYPE
            $table->foreignId('material_category_id')->constrained('material_categories');
            $table->foreignId('unit_id')->constrained('units'); 
            $table->decimal('stock', 15, 4)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
