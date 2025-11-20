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
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->string('do_number')->unique(); 

            
            $table->foreignId('sales_order_id')->constrained('sales_orders');
            
            
            $table->foreignId('buyer_id')->constrained('buyers');

            // Info Admin yang membuat
            $table->foreignId('user_id')->constrained('users');

            $table->date('delivery_date'); 
            $table->string('status'); 
            
            $table->text('notes')->nullable(); 
            $table->string('driver_name')->nullable(); 
            $table->string('vehicle_number')->nullable(); 

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_orders');
    }
};