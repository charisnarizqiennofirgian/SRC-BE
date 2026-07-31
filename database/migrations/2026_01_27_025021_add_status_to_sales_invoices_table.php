<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent guard -- lihat catatan di add_qty_m3_to_inventories_table.php,
        // pola korupsi migration yang sama (kolom sudah ada duluan di tempat lain).
        if (!Schema::hasColumn('sales_invoices', 'status')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->string('status')->default('DRAFT')->after('notes');
            });
        }
    }

    public function down(): void
    {
        // Tidak drop di sini -- lihat alasan sama di add_qty_m3_to_inventories_table.php.
    }
};
