<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        
        Schema::table('sales_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_orders', 'so_number')) {
                $table->string('so_number')->unique()->after('id');
            }
            if (!Schema::hasColumn('sales_orders', 'buyer_id')) {
                $table->foreignId('buyer_id')->constrained('buyers')->after('so_number');
            }
            if (!Schema::hasColumn('sales_orders', 'user_id')) {
                $table->foreignId('user_id')->constrained('users')->after('buyer_id');
            }
            if (!Schema::hasColumn('sales_orders', 'so_date')) {
                $table->date('so_date')->nullable()->after('user_id'); 
            }
            if (!Schema::hasColumn('sales_orders', 'delivery_date')) {
                $table->date('delivery_date')->nullable()->after('so_date');
            }
            if (!Schema::hasColumn('sales_orders', 'customer_po_number')) {
                $table->string('customer_po_number')->nullable()->after('delivery_date');
            }
            if (!Schema::hasColumn('sales_orders', 'subtotal')) {
                $table->decimal('subtotal', 15, 2)->default(0)->after('customer_po_number');
            }
            if (!Schema::hasColumn('sales_orders', 'discount')) {
                $table->decimal('discount', 15, 2)->default(0)->after('subtotal');
            }
            if (!Schema::hasColumn('sales_orders', 'tax_ppn')) {
                $table->decimal('tax_ppn', 15, 2)->default(0)->after('discount');
            }
            if (!Schema::hasColumn('sales_orders', 'grand_total')) {
                $table->decimal('grand_total', 15, 2)->default(0)->after('tax_ppn');
            }
            if (!Schema::hasColumn('sales_orders', 'currency')) {
                $table->string('currency', 3)->default('IDR')->after('grand_total');
            }
            if (!Schema::hasColumn('sales_orders', 'exchange_rate')) {
                $table->decimal('exchange_rate', 15, 4)->default(1)->after('currency');
            }
            if (!Schema::hasColumn('sales_orders', 'notes')) {
                $table->text('notes')->nullable()->after('exchange_rate');
            }
            if (!Schema::hasColumn('sales_orders', 'status')) {
                $table->string('status')->default('Draft')->after('notes');
            }
            if (!Schema::hasColumn('sales_orders', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });

        
        Schema::table('sales_order_details', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_order_details', 'sales_order_id')) {
                $table->foreignId('sales_order_id')->constrained('sales_orders')->onDelete('cascade')->after('id');
            }
            if (!Schema::hasColumn('sales_order_details', 'item_id')) {
                $table->foreignId('item_id')->constrained('items')->after('sales_order_id');
            }
            if (!Schema::hasColumn('sales_order_details', 'quantity')) {
                $table->decimal('quantity', 15, 4)->after('item_id');
            }
            if (!Schema::hasColumn('sales_order_details', 'quantity_shipped')) {
                $table->decimal('quantity_shipped', 15, 4)->default(0)->after('quantity');
            }
            if (!Schema::hasColumn('sales_order_details', 'item_name')) {
                $table->string('item_name')->after('quantity_shipped');
            }
            if (!Schema::hasColumn('sales_order_details', 'item_unit')) {
                $table->string('item_unit')->after('item_name');
            }
            if (!Schema::hasColumn('sales_order_details', 'unit_price')) {
                $table->decimal('unit_price', 15, 2)->after('item_unit');
            }
            if (!Schema::hasColumn('sales_order_details', 'discount')) {
                $table->decimal('discount', 15, 2)->default(0)->after('unit_price');
            }
            if (!Schema::hasColumn('sales_order_details', 'line_total')) {
                $table->decimal('line_total', 15, 2)->after('discount');
            }
            if (!Schema::hasColumn('sales_order_details', 'specifications')) {
                $table->json('specifications')->nullable()->after('line_total');
            }
        });
    }

    
    public function down(): void
    {
        
        Schema::table('sales_orders', function (Blueprint $table) {
            
            if (Schema::hasColumn('sales_orders', 'buyer_id')) {
                $table->dropForeign(['buyer_id']);
            }
            if (Schema::hasColumn('sales_orders', 'user_id')) {
                $table->dropForeign(['user_id']);
            }
            
            $table->dropColumn([
                'so_number', 'buyer_id', 'user_id', 'so_date', 'delivery_date',
                'customer_po_number', 'subtotal', 'discount', 'tax_ppn', 'grand_total',
                'currency', 'exchange_rate', 'notes', 'status', 'deleted_at'
            ]);
        });

        
        Schema::table('sales_order_details', function (Blueprint $table) {
            if (Schema::hasColumn('sales_order_details', 'sales_order_id')) {
                $table->dropForeign(['sales_order_id']);
            }
            if (Schema::hasColumn('sales_order_details', 'item_id')) {
                $table->dropForeign(['item_id']);
            }

            $table->dropColumn([
                'sales_order_id', 'item_id', 'quantity', 'quantity_shipped', 'item_name',
                'item_unit', 'unit_price', 'discount', 'line_total', 'specifications'
            ]);
        });
    }
};