<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->string('current_stage')
                  ->default('pending')
                  ->after('status')
                  ->comment('sawmill, pembahanan, moulding, assembly, finishing, packing');

            $table->boolean('skip_sawmill')
                  ->default(false)
                  ->after('current_stage')
                  ->comment('true = langsung ke pembahanan');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['current_stage', 'skip_sawmill']);
        });
    }
};
