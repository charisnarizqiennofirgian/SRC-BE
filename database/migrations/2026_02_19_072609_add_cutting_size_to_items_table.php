<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('cutting_t', 10, 2)->nullable()->after('volume_m3');
            $table->decimal('cutting_l', 10, 2)->nullable()->after('cutting_t');
            $table->decimal('cutting_p', 10, 2)->nullable()->after('cutting_l');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['cutting_t', 'cutting_l', 'cutting_p']);
        });
    }
};