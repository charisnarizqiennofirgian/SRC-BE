<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('jenis_kayu')->nullable()->after('bentuk');
            $table->string('tpk')->nullable()->after('jenis_kayu');
            $table->decimal('diameter', 10, 2)->nullable()->after('tpk');
            $table->decimal('panjang', 10, 2)->nullable()->after('diameter');
            $table->decimal('kubikasi', 10, 4)->nullable()->after('panjang');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['jenis_kayu', 'tpk', 'diameter', 'panjang', 'kubikasi']);
        });
    }
};
