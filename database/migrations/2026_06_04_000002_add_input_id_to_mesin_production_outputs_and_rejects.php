<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesin_production_outputs', function (Blueprint $table) {
            $table->unsignedBigInteger('mesin_production_input_id')->nullable()->after('mesin_production_id');
            $table->foreign('mesin_production_input_id')
                  ->references('id')->on('mesin_production_inputs')
                  ->nullOnDelete();
        });

        Schema::table('mesin_production_rejects', function (Blueprint $table) {
            $table->unsignedBigInteger('mesin_production_input_id')->nullable()->after('mesin_production_id');
            $table->foreign('mesin_production_input_id')
                  ->references('id')->on('mesin_production_inputs')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mesin_production_outputs', function (Blueprint $table) {
            $table->dropForeign(['mesin_production_input_id']);
            $table->dropColumn('mesin_production_input_id');
        });

        Schema::table('mesin_production_rejects', function (Blueprint $table) {
            $table->dropForeign(['mesin_production_input_id']);
            $table->dropColumn('mesin_production_input_id');
        });
    }
};
