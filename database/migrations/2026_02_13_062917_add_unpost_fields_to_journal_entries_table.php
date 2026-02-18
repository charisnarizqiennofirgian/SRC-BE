<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unsignedInteger('unposted_by')->nullable()->after('created_by');
            $table->datetime('unposted_at')->nullable()->after('unposted_by');
            $table->text('unpost_reason')->nullable()->after('unposted_at');
            $table->unsignedInteger('last_edited_by')->nullable()->after('unpost_reason');
            $table->datetime('last_edited_at')->nullable()->after('last_edited_by');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn([
                'unposted_by',
                'unposted_at',
                'unpost_reason',
                'last_edited_by',
                'last_edited_at'
            ]);
        });
    }
};