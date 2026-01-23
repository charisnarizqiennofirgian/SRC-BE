<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();

            // Link ke Purchase Bill
            $table->foreignId('purchase_bill_id')
                  ->constrained('purchase_bills')
                  ->onDelete('restrict');

            // Data Pembayaran
            $table->string('payment_number', 50)->unique(); // PAY-202601-0001
            $table->date('payment_date');                   // Tanggal bayar
            $table->decimal('amount', 15, 2);               // Nominal yang dibayar

            // Metode Pembayaran (Bank/Kas)
            $table->foreignId('payment_method_id')
                  ->constrained('payment_methods')
                  ->onDelete('restrict');

            // Link ke Jurnal (otomatis)
            $table->foreignId('journal_entry_id')
                  ->nullable()
                  ->constrained('journal_entries')
                  ->onDelete('set null');

            // Keterangan
            $table->text('notes')->nullable();

            // Audit
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index(['purchase_bill_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
    }
};
