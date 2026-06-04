<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah kolom jeblosan_id di tabel RST
        // agar tahu RST ini hasil dari jeblosan mana
        Schema::table('sawmill_production_rsts', function (Blueprint $table) {
            $table->foreignId('jeblosan_id')
                  ->nullable()
                  ->after('sawmill_production_id')
                  ->constrained('sawmill_production_rsts')
                  ->nullOnDelete();
        });

        // Tabel baru: detail jeblosan per proses sawmill
        Schema::create('sawmill_production_jeblosans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sawmill_production_id')
                  ->constrained('sawmill_productions')
                  ->onDelete('cascade');
            $table->foreignId('item_jeblosan_id')
                  ->constrained('items')
                  ->onDelete('cascade');
            $table->integer('qty_pcs');
            $table->decimal('volume_m3', 10, 6)->default(0);
            $table->boolean('is_sisa')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('sawmill_production_rsts', function (Blueprint $table) {
            $table->dropForeign(['jeblosan_id']);
            $table->dropColumn('jeblosan_id');
        });
        Schema::dropIfExists('sawmill_production_jeblosans');
    }
};
