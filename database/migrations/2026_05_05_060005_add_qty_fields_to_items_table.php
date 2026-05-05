<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('qty_set', 12, 4)->default(0)->after('qty_warna');
            $table->decimal('m3_natural', 12, 6)->default(0)->after('qty_set');
            $table->decimal('m3_warna', 12, 6)->default(0)->after('m3_natural');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['qty_set', 'm3_natural', 'm3_warna']);
        });
    }
};