<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('nama_produk')->nullable()->after('jenis_karton');
            $table->decimal('qty_natural', 12, 4)->default(0)->after('nama_produk');
            $table->decimal('qty_warna', 12, 4)->default(0)->after('qty_natural');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['nama_produk', 'qty_natural', 'qty_warna']);
        });
    }
};