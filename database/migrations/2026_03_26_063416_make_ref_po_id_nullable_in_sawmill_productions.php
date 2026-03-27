<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sawmill_productions', function (Blueprint $table) {
            $table->unsignedBigInteger('ref_po_id')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('sawmill_productions', function (Blueprint $table) {
            $table->dropColumn('ref_po_id');
        });
    }
};