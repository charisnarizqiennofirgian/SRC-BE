<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('supplier_document_number', 255)->nullable()->after('receipt_date');
        });
    }

    public function down()
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn('supplier_document_number');
        });
    }
};