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
        
        $categories = [
            'bahan_penolong',
            'karton_box',
            'operasional',
            'produk_jadi',
            'kayu_logs',
            'kayu_rst'
        ];

        
        Schema::table('items', function (Blueprint $table) use ($categories) {
            $table->enum('stock_category', $categories)
                  ->default('operasional') 
                  ->after('name'); 
        });
    }

    public function down(): void
    {
        
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('stock_category');
        });
    }
};