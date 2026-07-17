<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        $qcFinal = DB::table('warehouses')->where('code', 'QC_FINAL')->first();

        if (!$qcFinal) {
            DB::table('warehouses')->insert([
                'code'       => 'QC_FINAL',
                'name'       => 'Gudang QC Final',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        DB::table('warehouses')->where('code', 'QC_FINAL')->delete();
    }
};
