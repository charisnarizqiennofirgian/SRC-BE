<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent guard -- lihat catatan di add_qty_m3_to_inventories_table.php.
        // 'no_kapling' juga tidak selalu ada di database yang dibuat dari nol (kolom
        // itu sendiri produk migration terpisah yang riwayatnya sama tidak konsistennya) --
        // ->after() cuma cosmetic urutan kolom, aman diskip kalau referensinya tidak ada.
        if (!Schema::hasColumn('items', 'no_rak')) {
            Schema::table('items', function (Blueprint $table) {
                if (Schema::hasColumn('items', 'no_kapling')) {
                    $table->string('no_rak')->nullable()->after('no_kapling');
                } else {
                    $table->string('no_rak')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Tidak drop di sini -- lihat alasan sama di add_qty_m3_to_inventories_table.php.
    }
};