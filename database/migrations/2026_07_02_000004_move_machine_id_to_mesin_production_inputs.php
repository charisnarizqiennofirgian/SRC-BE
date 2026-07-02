<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesin_productions', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_id')->nullable()->change();
        });

        Schema::table('mesin_production_inputs', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_id')->nullable()->after('item_id');
            $table->foreign('machine_id')->references('id')->on('machines')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('mesin_production_inputs', function (Blueprint $table) {
            $table->dropForeign(['machine_id']);
            $table->dropColumn('machine_id');
        });

        Schema::table('mesin_productions', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_id')->nullable(false)->change();
        });
    }
};
