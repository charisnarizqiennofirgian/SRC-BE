<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesin_productions', function (Blueprint $table) {
            $table->unsignedBigInteger('production_order_detail_id')->nullable()->after('ref_po_id');
            $table->foreign('production_order_detail_id')
                  ->references('id')->on('production_order_details')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('mesin_productions', function (Blueprint $table) {
            $table->dropForeign(['production_order_detail_id']);
            $table->dropColumn('production_order_detail_id');
        });
    }
};
