<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        $exists = DB::table('warehouses')->where('code', 'RUSTIK_SAMPLE')->first();

        if (!$exists) {
            DB::table('warehouses')->insert([
                'code'       => 'RUSTIK_SAMPLE',
                'name'       => 'Gudang Rustik Sampel',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        DB::table('warehouses')->where('code', 'RUSTIK_SAMPLE')->delete();
    }
};
