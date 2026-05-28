<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_orders', 'shipment_date')) {
                $table->date('shipment_date')->nullable()->after('delivery_date');
            }
            if (!Schema::hasColumn('sales_orders', 'payment_term')) {
                $table->text('payment_term')->nullable()->after('shipment_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('sales_orders', 'shipment_date')) $cols[] = 'shipment_date';
            if (Schema::hasColumn('sales_orders', 'payment_term')) $cols[] = 'payment_term';
            if ($cols) $table->dropColumn($cols);
        });
    }
};
