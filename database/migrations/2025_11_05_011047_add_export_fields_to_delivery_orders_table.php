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
        Schema::table('delivery_orders', function (Blueprint $table) {
            
            $table->after('vehicle_number', function ($table) {
                $table->string('incoterm')->nullable(); 
                $table->string('container_seal')->nullable(); 
                $table->date('bl_date')->nullable(); 
                $table->string('vessel_name')->nullable(); 
                $table->string('mother_vessel')->nullable(); 
                
                
                $table->json('consignee_info')->nullable();
                $table->json('applicant_info')->nullable();
                $table->json('notify_info')->nullable();

                // Info Tambahan Packing List
                $table->string('eu_factory_number')->nullable();
                $table->string('port_of_loading')->nullable();
                $table->string('port_of_discharge')->nullable();
                $table->string('final_destination')->nullable();
                $table->string('bl_number')->nullable();
                $table->string('rex_info')->nullable();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            
            $table->dropColumn([
                'incoterm',
                'container_seal',
                'bl_date',
                'vessel_name',
                'mother_vessel',
                'consignee_info',
                'applicant_info',
                'notify_info',
                'eu_factory_number',
                'port_of_loading',
                'port_of_discharge',
                'final_destination',
                'bl_number',
                'rex_info',
            ]);
        });
    }
};