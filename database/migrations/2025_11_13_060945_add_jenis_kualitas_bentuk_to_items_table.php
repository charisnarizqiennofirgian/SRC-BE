<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('jenis')->nullable()->after('specifications');
            $table->string('kualitas')->nullable()->after('jenis');
            $table->string('bentuk')->nullable()->after('kualitas');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['jenis', 'kualitas', 'bentuk']);
        });
    }
};
