<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sawmill_production_rsts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sawmill_production_id')
                  ->constrained('sawmill_productions')
                  ->onDelete('cascade');
            $table->foreignId('item_rst_id')->constrained('items');
            $table->integer('qty_rst_pcs');
            $table->decimal('volume_rst_m3', 10, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sawmill_production_rsts');
    }
};
