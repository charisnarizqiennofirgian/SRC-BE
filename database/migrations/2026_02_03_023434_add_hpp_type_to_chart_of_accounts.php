<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE chart_of_accounts MODIFY COLUMN type ENUM('ASET', 'KEWAJIBAN', 'MODAL', 'PENDAPATAN', 'HPP', 'BIAYA') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE chart_of_accounts MODIFY COLUMN type ENUM('ASET', 'KEWAJIBAN', 'MODAL', 'PENDAPATAN', 'BIAYA') NOT NULL");
    }
};
