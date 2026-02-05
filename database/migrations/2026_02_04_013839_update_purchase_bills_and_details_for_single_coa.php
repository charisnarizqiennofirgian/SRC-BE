<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ✅ UPDATE purchase_bills: Tambah coa_id, hapus payment_method_id
        Schema::table('purchase_bills', function (Blueprint $table) {
            // Tambah coa_id (nullable dulu untuk data lama)
            $table->unsignedBigInteger('coa_id')->nullable()->after('payment_type');
            $table->foreign('coa_id')->references('id')->on('chart_of_accounts')->onDelete('restrict');

            // Hapus payment_method_id (jika ada)
            if (Schema::hasColumn('purchase_bills', 'payment_method_id')) {
                $table->dropForeign(['payment_method_id']);
                $table->dropColumn('payment_method_id');
            }
        });

        // ✅ UPDATE purchase_bill_details: Hapus account_id
        Schema::table('purchase_bill_details', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_bill_details', 'account_id')) {
                $table->dropForeign(['account_id']);
                $table->dropColumn('account_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rollback: Kembalikan struktur lama
        Schema::table('purchase_bills', function (Blueprint $table) {
            // Hapus coa_id
            if (Schema::hasColumn('purchase_bills', 'coa_id')) {
                $table->dropForeign(['coa_id']);
                $table->dropColumn('coa_id');
            }

            // Tambah kembali payment_method_id
            $table->unsignedBigInteger('payment_method_id')->nullable()->after('payment_type');
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->onDelete('set null');
        });

        Schema::table('purchase_bill_details', function (Blueprint $table) {
            // Tambah kembali account_id
            $table->unsignedBigInteger('account_id')->nullable()->after('specifications');
            $table->foreign('account_id')->references('id')->on('chart_of_accounts')->onDelete('restrict');
        });
    }
};
