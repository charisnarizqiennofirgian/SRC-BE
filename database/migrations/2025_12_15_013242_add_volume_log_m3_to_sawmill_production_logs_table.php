<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sawmill_production_logs', function (Blueprint $table) {
            $table->decimal('volume_log_m3', 15, 6)
                  ->default(0)
                  ->after('qty_log_pcs');
        });
    }

    public function down(): void
    {
        Schema::table('sawmill_production_logs', function (Blueprint $table) {
            $table->dropColumn('volume_log_m3');
        });
    }
};
