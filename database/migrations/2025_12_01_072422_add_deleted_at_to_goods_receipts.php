<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->softDeletes(); // Ini akan tambahkan kolom `deleted_at`
        });
    }

    public function down()
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};