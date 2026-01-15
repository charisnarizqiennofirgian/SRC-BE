<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Check if REJECT warehouse exists
        $reject = DB::table('warehouses')->where('code', 'REJECT')->first();

        if (!$reject) {
            DB::table('warehouses')->insert([
                'code' => 'REJECT',
                'name' => 'Gudang Reject / Rusak',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        DB::table('warehouses')->where('code', 'REJECT')->delete();
    }
};
