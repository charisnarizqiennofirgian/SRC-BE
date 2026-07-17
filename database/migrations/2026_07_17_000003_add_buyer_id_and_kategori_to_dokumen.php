<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dokumen', function (Blueprint $table) {
            $table->foreignId('buyer_id')->nullable()->after('kategori')
                  ->constrained('buyers')->nullOnDelete();
        });

        DB::statement("ALTER TABLE dokumen MODIFY kategori ENUM('Gambar Produk','Daftar Bahan','Gambar Teknik','File Buyer','Lainnya') DEFAULT 'Lainnya'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE dokumen MODIFY kategori ENUM('Gambar Produk','Daftar Bahan','Gambar Teknik','Lainnya') DEFAULT 'Lainnya'");

        Schema::table('dokumen', function (Blueprint $table) {
            $table->dropForeign(['buyer_id']);
            $table->dropColumn('buyer_id');
        });
    }
};
