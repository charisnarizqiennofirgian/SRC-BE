<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            // Tipe Pembayaran: TEMPO, TUNAI, DP
            $table->enum('payment_type', ['TEMPO', 'TUNAI', 'DP'])->default('TEMPO')->after('status');

            // Untuk pembayaran TUNAI atau DP
            $table->foreignId('payment_method_id')->nullable()->after('payment_type')
                  ->constrained('payment_methods')->nullOnDelete();

            // Nominal yang sudah dibayar (untuk DP atau TUNAI)
            $table->decimal('paid_amount', 15, 2)->default(0)->after('payment_method_id');

            // Sisa hutang
            $table->decimal('remaining_amount', 15, 2)->default(0)->after('paid_amount');

            // Referensi ke jurnal
            $table->foreignId('journal_entry_id')->nullable()->after('remaining_amount')
                  ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropForeign(['journal_entry_id']);
            $table->dropColumn([
                'payment_type',
                'payment_method_id',
                'paid_amount',
                'remaining_amount',
                'journal_entry_id',
            ]);
        });
    }
};
