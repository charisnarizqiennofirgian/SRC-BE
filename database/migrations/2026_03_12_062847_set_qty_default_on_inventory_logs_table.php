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
    Schema::table('inventory_logs', function (Blueprint $table) {
        $table->decimal('qty', 15, 4)->default(0)->change();
    });
}

public function down(): void
{
    Schema::table('inventory_logs', function (Blueprint $table) {
        $table->decimal('qty', 15, 4)->nullable()->change();
    });
}
};
