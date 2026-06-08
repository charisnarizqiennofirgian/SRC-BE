<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('currency', 3)->default('IDR')->after('notes');
            $table->decimal('exchange_rate', 15, 4)->default(1.0000)->after('currency');
        });

        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->string('currency', 3)->default('IDR')->after('notes');
            $table->decimal('exchange_rate', 15, 4)->default(1.0000)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate']);
        });

        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate']);
        });
    }
};
