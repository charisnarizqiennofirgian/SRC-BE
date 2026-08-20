<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('inventories', 'grade_key')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->string('grade_key', 50)->storedAs("COALESCE(grade, '')")->after('grade');
            });
        }

        $indexExists = DB::select(
            "SHOW INDEX FROM inventories WHERE Key_name = 'inventories_wh_item_grade_unique'"
        );

        if (empty($indexExists)) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->unique(['warehouse_id', 'item_id', 'grade_key'], 'inventories_wh_item_grade_unique');
            });
        }
    }

    public function down(): void
    {
        $indexExists = DB::select(
            "SHOW INDEX FROM inventories WHERE Key_name = 'inventories_wh_item_grade_unique'"
        );

        if (!empty($indexExists)) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->dropUnique('inventories_wh_item_grade_unique');
            });
        }

        if (Schema::hasColumn('inventories', 'grade_key')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->dropColumn('grade_key');
            });
        }
    }
};
