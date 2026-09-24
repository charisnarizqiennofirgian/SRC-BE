<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('opname_number', 50)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->date('opname_date');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('stock_opname_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained('stock_opnames')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items');
            $table->string('grade', 50)->nullable();
            $table->string('row_type', 20)->default('pcs');
            $table->boolean('is_manual')->default(false);

            $table->decimal('system_qty_pcs', 15, 4)->default(0);
            $table->decimal('system_qty_natural', 15, 4)->default(0);
            $table->decimal('system_qty_warna', 15, 4)->default(0);
            $table->decimal('system_qty_m3', 15, 6)->default(0);

            $table->decimal('real_qty_pcs', 15, 4)->nullable();
            $table->decimal('real_qty_natural', 15, 4)->nullable();
            $table->decimal('real_qty_warna', 15, 4)->nullable();

            $table->decimal('posted_system_qty_pcs', 15, 4)->nullable();
            $table->decimal('diff_qty_pcs', 15, 4)->nullable();
            $table->decimal('diff_qty_m3', 15, 6)->nullable();

            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['stock_opname_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_details');
        Schema::dropIfExists('stock_opnames');
    }
};
