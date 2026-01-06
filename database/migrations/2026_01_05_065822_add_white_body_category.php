<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('categories')->insert([
            'name' => 'White Body',
            'description' => 'Barang hasil rakitan mentah (setengah jadi)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('categories')->where('name', 'White Body')->delete();
    }
};