<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Idempotent guard -- lihat catatan di add_qty_m3_to_inventories_table.php.
        // create_inventories_table sudah langsung pakai nama qty_pcs sejak awal di
        // database yang dibuat dari nol, jadi kolom 'qty' lama itu tidak pernah ada.
        if (Schema::hasColumn('inventories', 'qty') && !Schema::hasColumn('inventories', 'qty_pcs')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->renameColumn('qty', 'qty_pcs');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Tidak rename balik di sini -- lihat alasan sama di add_qty_m3_to_inventories_table.php.
    }
};
