<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();

            // Relasi
            $table->foreignId('sales_order_id')->constrained('sales_orders');
            $table->foreignId('delivery_order_id')->nullable()->constrained('delivery_orders');
            $table->foreignId('buyer_id')->constrained('buyers');
            $table->foreignId('user_id')->constrained('users'); // Admin yang buat invoice

            // Tanggal
            $table->date('invoice_date');
            $table->date('due_date')->nullable();

            // Currency & Exchange Rate (Untuk ekspor)
            $table->string('currency', 3)->default('IDR'); // IDR, USD, dll
            $table->decimal('exchange_rate', 15, 4)->default(1); // Kurs saat invoice dibuat

            // Nominal dalam currency asli
            $table->decimal('subtotal_original', 15, 2)->default(0);
            $table->decimal('discount_original', 15, 2)->default(0);
            $table->decimal('tax_ppn_original', 15, 2)->default(0);
            $table->decimal('grand_total_original', 15, 2)->default(0);

            // Nominal dalam IDR (untuk jurnal)
            $table->decimal('subtotal_idr', 15, 2)->default(0);
            $table->decimal('discount_idr', 15, 2)->default(0);
            $table->decimal('tax_ppn_idr', 15, 2)->default(0);
            $table->decimal('grand_total_idr', 15, 2)->default(0);

            // Payment tracking
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2)->default(0);
            $table->string('payment_status')->default('UNPAID'); // UNPAID, PARTIAL, PAID

            // Jurnal
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');

            $table->text('notes')->nullable();
            $table->string('status')->default('DRAFT'); // DRAFT, POSTED, CANCELLED

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
