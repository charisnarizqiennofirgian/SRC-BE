<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 50)->unique();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->onDelete('restrict');
            $table->foreignId('buyer_id')->constrained('buyers')->onDelete('restrict');

            $table->date('payment_date');
            $table->decimal('amount', 15, 2);

            // Payment Method & Journal
            $table->foreignId('payment_method_id')->constrained('payment_methods')->onDelete('restrict');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->onDelete('set null');

            // Tipe pembayaran
            $table->enum('payment_type', ['CASH', 'DP'])->default('CASH');
            $table->foreignId('down_payment_id')->nullable()->constrained('down_payments')->onDelete('set null');

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
