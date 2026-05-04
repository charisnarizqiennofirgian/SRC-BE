<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_dimension_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->onDelete('cascade');
            $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->decimal('old_p', 10, 2)->nullable();
            $table->decimal('old_l', 10, 2)->nullable();
            $table->decimal('old_t', 10, 2)->nullable();
            $table->decimal('new_p', 10, 2)->nullable();
            $table->decimal('new_l', 10, 2)->nullable();
            $table->decimal('new_t', 10, 2)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_dimension_histories');
    }
};