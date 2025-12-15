<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sawmill_productions', function (Blueprint $table) {
            $table->decimal('total_log_m3', 10, 3)->default(0);
            $table->decimal('total_rst_m3', 10, 3)->default(0);
            $table->decimal('yield_percent', 5, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sawmill_productions', function (Blueprint $table) {
            $table->dropColumn(['total_log_m3', 'total_rst_m3', 'yield_percent']);
        });
    }
};
