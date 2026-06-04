<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sawmill_productions', function (Blueprint $table) {
            $table->enum('process_type', ['log_jeblosan', 'jeblosan_rst'])
                  ->default('log_jeblosan')
                  ->after('document_number');
        });
    }

    public function down(): void
    {
        Schema::table('sawmill_productions', function (Blueprint $table) {
            $table->dropColumn('process_type');
        });
    }
};
