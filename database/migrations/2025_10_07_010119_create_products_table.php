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
    Schema::create('products', function (Blueprint $table) {
        $table->id();
        $table->string('code')->unique();
        $table->string('name');
        $table->decimal('price', 15, 2)->default(0);
        $table->text('description')->nullable();
        $table->foreignId('category_id')->constrained('categories');
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
        Schema::dropIfExists('products');
    }
};