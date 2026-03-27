<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sawmill_productions', function (Blueprint $table) {
            $table->date('estimated_finish_date')->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('sawmill_productions', function (Blueprint $table) {
            $table->dropColumn('estimated_finish_date');
        });
    }
};