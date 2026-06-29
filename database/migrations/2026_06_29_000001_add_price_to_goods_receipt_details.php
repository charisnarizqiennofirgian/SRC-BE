<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_details', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->nullable()->after('quantity_received');
            $table->decimal('subtotal', 15, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_details', function (Blueprint $table) {
            $table->dropColumn(['price', 'subtotal']);
        });
    }
};
