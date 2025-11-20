<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->onDelete('cascade');
            $table->string('code')->nullable()->unique();
            $table->string('name');
            $table->decimal('total_wood_m3', 15, 6)->default(0); 
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['item_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boms');
    }
};