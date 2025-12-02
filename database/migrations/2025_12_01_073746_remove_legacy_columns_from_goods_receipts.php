<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            
            $table->dropForeign(['item_id']);
            
            
            $table->dropColumn(['item_id', 'quantity_received']);
        });
    }

    public function down()
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            
            $table->unsignedBigInteger('item_id')->nullable()->after('purchase_order_id');
            $table->decimal('quantity_received', 15, 2)->nullable()->after('item_id');
            
            
            $table->foreign('item_id')->references('id')->on('items')->onDelete('set null');
        });
    }
};