<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            // Akun Kas/Bank untuk pembayaran TUNAI dan DP
            $table->foreignId('kas_account_id')
                  ->nullable()
                  ->after('coa_id')
                  ->constrained('chart_of_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropForeign(['kas_account_id']);
            $table->dropColumn('kas_account_id');
        });
    }
};
