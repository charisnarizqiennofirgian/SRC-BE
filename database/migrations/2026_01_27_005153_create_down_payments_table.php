<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('down_payments', function (Blueprint $table) {
            $table->id();
            $table->string('dp_number', 50)->unique();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->onDelete('restrict');
            $table->foreignId('buyer_id')->constrained('buyers')->onDelete('restrict');
            $table->date('payment_date');

            // Currency & Exchange Rate
            $table->string('currency', 3)->default('IDR');
            $table->decimal('exchange_rate', 15, 4)->default(1.0000);

            // Amount dalam currency asli dan IDR
            $table->decimal('amount_original', 15, 2)->default(0);
            $table->decimal('amount_idr', 15, 2)->default(0);

            // Payment Method & Journal
            $table->foreignId('payment_method_id')->constrained('payment_methods')->onDelete('restrict');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->onDelete('set null');

            // Status & Tracking
            $table->enum('status', ['PENDING', 'USED', 'REFUNDED'])->default('PENDING');
            $table->decimal('used_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2)->default(0);

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('down_payments');
    }
};
