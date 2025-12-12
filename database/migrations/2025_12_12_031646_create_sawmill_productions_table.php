<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sawmill_productions', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique(); // SW-YYYYMM-XXX
            $table->date('date');
            $table->foreignId('warehouse_from_id')->constrained('warehouses');
            $table->foreignId('warehouse_to_id')->constrained('warehouses');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sawmill_productions');
    }
};
