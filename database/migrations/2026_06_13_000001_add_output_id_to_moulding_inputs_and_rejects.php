<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // N inputs can now map to 1 output (group concept: output record IS the group)
        Schema::table('moulding_production_inputs', function (Blueprint $table) {
            $table->unsignedBigInteger('moulding_production_output_id')->nullable()->after('moulding_production_id');
            $table->foreign('moulding_production_output_id', 'mp_inputs_output_id_fk')
                  ->references('id')->on('moulding_production_outputs')
                  ->nullOnDelete();
        });

        Schema::table('moulding_production_rejects', function (Blueprint $table) {
            $table->unsignedBigInteger('moulding_production_output_id')->nullable()->after('moulding_production_id');
            $table->foreign('moulding_production_output_id', 'mp_rejects_output_id_fk')
                  ->references('id')->on('moulding_production_outputs')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('moulding_production_inputs', function (Blueprint $table) {
            $table->dropForeign('mp_inputs_output_id_fk');
            $table->dropColumn('moulding_production_output_id');
        });

        Schema::table('moulding_production_rejects', function (Blueprint $table) {
            $table->dropForeign('mp_rejects_output_id_fk');
            $table->dropColumn('moulding_production_output_id');
        });
    }
};
