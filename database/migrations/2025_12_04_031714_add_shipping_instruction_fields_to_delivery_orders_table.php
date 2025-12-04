<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            // 3 Kolom Baru untuk Shipping Instruction
            $table->text('forwarder_name')->nullable()->after('notes'); // Nama & Alamat Forwarder (Messrs)
            $table->string('peb_number', 100)->nullable()->after('bl_number'); // Nomor PEB
            $table->string('container_type', 50)->nullable()->after('container_number'); // Tipe Container (20ft, 40HC, dll)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn(['forwarder_name', 'peb_number', 'container_type']);
        });
    }
};
