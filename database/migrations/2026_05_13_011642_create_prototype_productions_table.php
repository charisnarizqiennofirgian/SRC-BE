<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prototype_productions', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->date('date');
            $table->foreignId('ref_po_id')->constrained('production_orders')->onDelete('cascade');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prototype_productions');
    }
};