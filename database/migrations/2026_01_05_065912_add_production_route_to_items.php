<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->enum('production_route', ['sanding', 'rustik'])
                  ->default('sanding')
                  ->after('description')
                  ->comment('Jalur finishing setelah assembling');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('production_route');
        });
    }
};