<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sawmill_production_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sawmill_production_id')
                  ->constrained('sawmill_productions')
                  ->onDelete('cascade');
            $table->foreignId('item_log_id')->constrained('items');
            $table->integer('qty_log_pcs');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sawmill_production_logs');
    }
};
