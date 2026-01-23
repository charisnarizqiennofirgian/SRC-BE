<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            // Tambah field tracking pembayaran (kalau belum ada)
            if (!Schema::hasColumn('purchase_bills', 'payment_status')) {
                $table->enum('payment_status', ['UNPAID', 'PARTIAL', 'PAID'])
                      ->default('UNPAID')
                      ->after('status');
            }

            // Total yang sudah dibayar (kalau belum ada)
            if (!Schema::hasColumn('purchase_bills', 'paid_amount')) {
                $table->decimal('paid_amount', 15, 2)
                      ->default(0)
                      ->after('total_amount');
            }

            // Sisa yang belum dibayar (kalau belum ada)
            if (!Schema::hasColumn('purchase_bills', 'remaining_amount')) {
                $table->decimal('remaining_amount', 15, 2)
                      ->default(0)
                      ->after('paid_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'paid_amount', 'remaining_amount']);
        });
    }
};
