<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent guard -- lihat catatan di add_qty_m3_to_inventories_table.php.
        if (!Schema::hasColumn('sales_invoices', 'user_id')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('buyer_id');

                $table->foreign('user_id')
                      ->references('id')
                      ->on('users')
                      ->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        // Tidak drop di sini -- lihat alasan sama di add_qty_m3_to_inventories_table.php.
    }
};
