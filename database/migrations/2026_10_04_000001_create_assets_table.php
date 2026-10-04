<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('category', 30)->index();
            $table->string('brand')->nullable();
            $table->string('model_type')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('location')->nullable()->index();
            $table->string('pic')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->unsignedSmallInteger('useful_life_years')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('condition', 20)->default('baik')->index();
            $table->string('status', 20)->default('aktif')->index();
            $table->string('plate_number', 30)->nullable();
            $table->string('chassis_number')->nullable();
            $table->string('engine_number')->nullable();
            $table->date('tax_due_date')->nullable();
            $table->date('kir_due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asset_service_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('service_date')->index();
            $table->string('service_type', 30);
            $table->text('description');
            $table->string('vendor')->nullable();
            $table->decimal('cost', 15, 2)->default(0);
            $table->string('meter_reading', 50)->nullable();
            $table->date('next_service_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_service_records');
        Schema::dropIfExists('assets');
    }
};
