<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddContainerFieldsToDeliveryOrdersTable extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->string('container_number')->nullable()->after('freight_terms');
            $table->string('seal_number')->nullable()->after('container_number');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn(['container_number', 'seal_number']);
        });
    }
}
