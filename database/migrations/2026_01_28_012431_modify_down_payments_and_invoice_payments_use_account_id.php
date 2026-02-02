<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ubah tabel down_payments
        Schema::table('down_payments', function (Blueprint $table) {
            // Cek dan hapus foreign key dengan nama yang mungkin
            if (Schema::hasColumn('down_payments', 'payment_method_id')) {
                // Try to drop foreign key (ignore error jika tidak ada)
                try {
                    $table->dropForeign(['payment_method_id']);
                } catch (\Exception $e) {
                    // Foreign key mungkin tidak ada, lanjut saja
                }
                $table->dropColumn('payment_method_id');
            }
        });

        Schema::table('down_payments', function (Blueprint $table) {
            // Tambah kolom account_id (kalau belum ada)
            if (!Schema::hasColumn('down_payments', 'account_id')) {
                $table->unsignedBigInteger('account_id')->after('buyer_id');
                $table->foreign('account_id')
                    ->references('id')
                    ->on('chart_of_accounts')
                    ->onDelete('restrict');
            }
        });

        // 2. Ubah tabel invoice_payments
        Schema::table('invoice_payments', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_payments', 'payment_method_id')) {
                try {
                    $table->dropForeign(['payment_method_id']);
                } catch (\Exception $e) {
                    // Ignore
                }
                $table->dropColumn('payment_method_id');
            }
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_payments', 'account_id')) {
                $table->unsignedBigInteger('account_id')->after('payment_date');
                $table->foreign('account_id')
                    ->references('id')
                    ->on('chart_of_accounts')
                    ->onDelete('restrict');
            }
        });
    }

    public function down(): void
    {
        // Rollback: kembalikan ke payment_method_id
        Schema::table('down_payments', function (Blueprint $table) {
            if (Schema::hasColumn('down_payments', 'account_id')) {
                try {
                    $table->dropForeign(['account_id']);
                } catch (\Exception $e) {
                    // Ignore
                }
                $table->dropColumn('account_id');
            }
        });

        Schema::table('down_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('down_payments', 'payment_method_id')) {
                $table->unsignedBigInteger('payment_method_id')->after('buyer_id');
                $table->foreign('payment_method_id')
                    ->references('id')
                    ->on('payment_methods')
                    ->onDelete('restrict');
            }
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_payments', 'account_id')) {
                try {
                    $table->dropForeign(['account_id']);
                } catch (\Exception $e) {
                    // Ignore
                }
                $table->dropColumn('account_id');
            }
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_payments', 'payment_method_id')) {
                $table->unsignedBigInteger('payment_method_id')->after('payment_date');
                $table->foreign('payment_method_id')
                    ->references('id')
                    ->on('payment_methods')
                    ->onDelete('restrict');
            }
        });
    }
};
