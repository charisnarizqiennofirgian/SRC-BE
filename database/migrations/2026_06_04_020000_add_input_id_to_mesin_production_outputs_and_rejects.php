<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // File ini duplikat PERSIS dari 2026_06_04_000002_add_input_id_to_mesin_production_outputs_and_rejects.php
        // (kemungkinan ke-commit dobel dengan timestamp beda). Guard idempotent, bukan
        // dihapus, supaya tidak ganggu histori migration di server manapun yang mungkin
        // sudah mencatat file ini -- lihat catatan lengkap di add_qty_m3_to_inventories_table.php.
        if (!Schema::hasColumn('mesin_production_outputs', 'mesin_production_input_id')) {
            Schema::table('mesin_production_outputs', function (Blueprint $table) {
                $table->unsignedBigInteger('mesin_production_input_id')->nullable()->after('mesin_production_id');
                $table->foreign('mesin_production_input_id')
                      ->references('id')->on('mesin_production_inputs')
                      ->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('mesin_production_rejects', 'mesin_production_input_id')) {
            Schema::table('mesin_production_rejects', function (Blueprint $table) {
                $table->unsignedBigInteger('mesin_production_input_id')->nullable()->after('mesin_production_id');
                $table->foreign('mesin_production_input_id')
                      ->references('id')->on('mesin_production_inputs')
                      ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Tidak drop di sini -- lihat alasan sama di add_qty_m3_to_inventories_table.php.
    }
};
