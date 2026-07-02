<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assembling_production_inputs', function (Blueprint $table) {
            $table->enum('finishing', ['natural', 'warna'])->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('assembling_production_inputs', function (Blueprint $table) {
            $table->dropColumn('finishing');
        });
    }
};
