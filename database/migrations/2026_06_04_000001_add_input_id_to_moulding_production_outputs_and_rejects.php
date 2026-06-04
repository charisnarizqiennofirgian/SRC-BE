<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moulding_production_outputs', function (Blueprint $table) {
            $table->unsignedBigInteger('moulding_production_input_id')->nullable()->after('moulding_production_id');
            $table->foreign('moulding_production_input_id')
                  ->references('id')->on('moulding_production_inputs')
                  ->nullOnDelete();
        });

        Schema::table('moulding_production_rejects', function (Blueprint $table) {
            $table->unsignedBigInteger('moulding_production_input_id')->nullable()->after('moulding_production_id');
            $table->foreign('moulding_production_input_id')
                  ->references('id')->on('moulding_production_inputs')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('moulding_production_outputs', function (Blueprint $table) {
            $table->dropForeign(['moulding_production_input_id']);
            $table->dropColumn('moulding_production_input_id');
        });

        Schema::table('moulding_production_rejects', function (Blueprint $table) {
            $table->dropForeign(['moulding_production_input_id']);
            $table->dropColumn('moulding_production_input_id');
        });
    }
};
