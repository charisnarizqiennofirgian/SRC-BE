<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('pr_number')->unique();
            $table->foreignId('so_id')->nullable()->constrained('sales_orders')->onDelete('set null');
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->date('deadline');
            $table->text('notes')->nullable();
            $table->enum('status', ['draft', 'submitted', 'in_progress', 'completed', 'cancelled'])
                  ->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};