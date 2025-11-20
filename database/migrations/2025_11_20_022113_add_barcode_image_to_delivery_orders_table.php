<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->string('barcode_image')->nullable()->after('id');
            // Ubah 'id' sesuai field yang kamu mau letakkan setelahnya
        });
    }

    public function down()
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn('barcode_image');
        });
    }
};
