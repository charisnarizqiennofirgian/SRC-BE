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
        // ✅ UPDATE TABLE: goods_receipt_details
        Schema::table('goods_receipt_details', function (Blueprint $table) {
            // Tambah kolom purchase_order_detail_id (link ke PO Detail)
            if (!Schema::hasColumn('goods_receipt_details', 'purchase_order_detail_id')) {
                $table->foreignId('purchase_order_detail_id')
                    ->nullable()
                    ->after('goods_receipt_id')
                    ->constrained('purchase_order_details')
                    ->onDelete('cascade');
            }

            // Tambah kolom billed (status sudah ditagih atau belum)
            if (!Schema::hasColumn('goods_receipt_details', 'billed')) {
                $table->boolean('billed')
                    ->default(false)
                    ->after('quantity_received');
            }
        });

        // ✅ UPDATE TABLE: purchase_bill_details
        Schema::table('purchase_bill_details', function (Blueprint $table) {
            // Tambah kolom specifications (untuk simpan P, L, T, dll)
            if (!Schema::hasColumn('purchase_bill_details', 'specifications')) {
                $table->json('specifications')
                    ->nullable()
                    ->after('subtotal');
            }
        });
    }

    /**
     * Reverse the migrations (untuk rollback)
     */
    public function down(): void
    {
        
        Schema::table('goods_receipt_details', function (Blueprint $table) {
            if (Schema::hasColumn('goods_receipt_details', 'purchase_order_detail_id')) {
                $table->dropForeign(['purchase_order_detail_id']);
                $table->dropColumn('purchase_order_detail_id');
            }
            if (Schema::hasColumn('goods_receipt_details', 'billed')) {
                $table->dropColumn('billed');
            }
        });

        Schema::table('purchase_bill_details', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_bill_details', 'specifications')) {
                $table->dropColumn('specifications');
            }
        });
    }
};
