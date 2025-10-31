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
        Schema::create('items', function (Blueprint $table) {
            
            $table->id();
            $table->string('name');
            $table->string('code')->unique()->nullable();
            $table->foreignId('category_id')->constrained('categories');
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('stock', 15, 4)->default(0);
            $table->text('description')->nullable();

            
            $table->json('specifications')->nullable(); 

            $table->decimal('nw_per_box', 15, 4)->nullable();

            $table->decimal('gw_per_box', 15, 4)->nullable();
            
            
            $table->decimal('wood_consumed_per_pcs', 15, 6)->nullable();

            

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};