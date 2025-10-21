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
        
        Schema::table('purchase_orders', function (Blueprint $table) {
            // Hapus kolom lama yang akan kita ganti
            if (Schema::hasColumn('purchase_orders', 'total_amount')) {
                $table->dropColumn('total_amount');
            }

            
            $table->decimal('subtotal', 15, 2)->default(0)->after('status');
            $table->decimal('ppn_percentage', 5, 2)->default(0)->after('subtotal');
            $table->decimal('ppn_amount', 15, 2)->default(0)->after('ppn_percentage');
            $table->decimal('grand_total', 15, 2)->default(0)->after('ppn_amount');
            $table->softDeletes()->after('updated_at'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 2)->default(0);

            $table->dropColumn([
                'subtotal',
                'ppn_percentage',
                'ppn_amount',
                'grand_total',
            ]);
            $table->dropSoftDeletes();
        });
    }
};