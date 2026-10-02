<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_cancellations', function (Blueprint $table) {
            $table->id();
            $table->string('document_number', 50)->index();
            $table->string('stage', 30)->index();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('ref_po_id')->nullable()->index();
            $table->string('po_number')->nullable();
            $table->date('document_date')->nullable();
            $table->text('reason');
            $table->longText('snapshot');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_cancellations');
    }
};
