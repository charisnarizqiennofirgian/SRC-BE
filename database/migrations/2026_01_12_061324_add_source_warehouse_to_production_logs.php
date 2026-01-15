<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('production_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('source_warehouse_id')->nullable()->after('stage');
            $table->unsignedBigInteger('destination_warehouse_id')->nullable()->after('source_warehouse_id');

            $table->foreign('source_warehouse_id')->references('id')->on('warehouses')->onDelete('set null');
            $table->foreign('destination_warehouse_id')->references('id')->on('warehouses')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('production_logs', function (Blueprint $table) {
            $table->dropForeign(['source_warehouse_id']);
            $table->dropForeign(['destination_warehouse_id']);
            $table->dropColumn(['source_warehouse_id', 'destination_warehouse_id']);
        });
    }
};
