<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->foreignId('receivable_account_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('chart_of_accounts')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropForeign(['receivable_account_id']);
            $table->dropColumn('receivable_account_id');
        });
    }
};
