<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_details', function (Blueprint $table) {
            $table->timestamp('moulding_completed_at')->nullable()->after('current_stage');
            $table->foreignId('moulding_completed_by')->nullable()->after('moulding_completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('mesin_completed_at')->nullable()->after('moulding_completed_by');
            $table->foreignId('mesin_completed_by')->nullable()->after('mesin_completed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_order_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moulding_completed_by');
            $table->dropConstrainedForeignId('mesin_completed_by');
            $table->dropColumn(['moulding_completed_at', 'mesin_completed_at']);
        });
    }
};
