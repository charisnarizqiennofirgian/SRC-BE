<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moulding_production_outputs', function (Blueprint $table) {
            $table->enum('finishing', ['natural', 'warna'])->default('natural')->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('moulding_production_outputs', function (Blueprint $table) {
            $table->dropColumn('finishing');
        });
    }
};
