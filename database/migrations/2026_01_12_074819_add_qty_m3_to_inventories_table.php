<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // qty_m3 sudah ada sejak create_inventories_table (2025_12_17) -- migration ini
        // jadi redundant di database yang dibuat dari nol (mis. test/sqlite). Guard supaya
        // idempotent, tidak mengubah histori migration di database live yang migration ini
        // sudah pernah kejalan duluan (before create_inventories_table diedit nambahin kolom itu).
        if (!Schema::hasColumn('inventories', 'qty_m3')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->decimal('qty_m3', 15, 6)->default(0)->after('qty_pcs');
            });
        }
    }

    public function down()
    {
        // Tidak drop qty_m3 di sini -- kolomnya milik create_inventories_table, bukan migration
        // ini. Drop di sini cuma benar kalau migration ini yang menciptakannya, yang pada
        // database manapun yang dibuat dari create_inventories_table terbaru itu tidak benar.
    }
};
