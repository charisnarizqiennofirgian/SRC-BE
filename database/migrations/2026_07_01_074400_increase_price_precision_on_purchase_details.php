<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Harga satuan dalam foreign currency (EUR/USD) bisa sangat kecil, misal 0.04756
    // decimal(15,2) memotong jadi 0.05 — naik ke decimal(15,5)
    public function up(): void
    {
        Schema::table('purchase_order_details', function (Blueprint $table) {
            $table->decimal('price', 15, 5)->change();
        });

        Schema::table('purchase_bill_details', function (Blueprint $table) {
            $table->decimal('price', 15, 5)->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_details', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
        });

        Schema::table('purchase_bill_details', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
        });
    }
};
