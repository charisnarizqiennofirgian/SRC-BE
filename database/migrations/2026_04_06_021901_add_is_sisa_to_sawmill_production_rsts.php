<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('sawmill_production_rsts', function (Blueprint $table) {
        $table->boolean('is_sisa')->default(false)->after('volume_rst_m3');
        $table->unsignedBigInteger('destination_warehouse_id')->nullable()->after('is_sisa');
    });
}

public function down()
{
    Schema::table('sawmill_production_rsts', function (Blueprint $table) {
        $table->dropColumn(['is_sisa', 'destination_warehouse_id']);
    });
}
};
