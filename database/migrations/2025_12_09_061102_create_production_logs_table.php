<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_logs', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('reference_number');
            $table->string('process_type');
            $table->string('stage')->nullable();
            $table->foreignId('input_item_id')->constrained('items');
            $table->decimal('input_quantity', 12, 4);
            $table->foreignId('output_item_id')->constrained('items');
            $table->decimal('output_quantity', 12, 4);
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_logs');
    }
};
