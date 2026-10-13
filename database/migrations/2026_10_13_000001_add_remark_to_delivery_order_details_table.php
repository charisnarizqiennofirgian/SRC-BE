<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_order_details', function (Blueprint $table) {
            $table->string('remark', 255)->nullable()->after('quantity_crates');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_order_details', function (Blueprint $table) {
            $table->dropColumn('remark');
        });
    }
};
