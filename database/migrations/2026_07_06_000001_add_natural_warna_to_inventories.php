<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->decimal('qty_natural', 15, 4)->default(0)->after('qty_pcs');
            $table->decimal('qty_warna', 15, 4)->default(0)->after('qty_natural');
        });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn(['qty_natural', 'qty_warna']);
        });
    }
};
