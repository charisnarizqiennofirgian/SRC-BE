<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('length_mm', 10, 2)->nullable()->after('stock');
            $table->decimal('width_mm', 10, 2)->nullable()->after('length_mm');
            $table->decimal('thickness_mm', 10, 2)->nullable()->after('width_mm');
            $table->decimal('diameter_mm', 10, 2)->nullable()->after('thickness_mm');
            $table->decimal('volume_m3', 12, 6)->nullable()->after('diameter_mm');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn([
                'length_mm',
                'width_mm',
                'thickness_mm',
                'diameter_mm',
                'volume_m3',
            ]);
        });
    }
};
