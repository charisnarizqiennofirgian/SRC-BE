<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dokumen', function (Blueprint $table) {
            $table->id();
            $table->string('nama_file');
            $table->string('nama_asli');
            $table->string('path_file');
            $table->string('tipe_file');
            $table->bigInteger('ukuran_file');
            $table->enum('kategori', [
                'Gambar Produk',
                'Daftar Bahan',
                'Gambar Teknik',
                'Lainnya'
            ])->default('Lainnya');
            $table->text('keterangan')->nullable();
            $table->foreignId('diupload_oleh')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dokumen');
    }
};
