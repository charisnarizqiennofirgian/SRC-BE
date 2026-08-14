<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $indexExists = DB::select(
            "SHOW INDEX FROM inventories WHERE Key_name = 'inventories_item_id_warehouse_id_index'"
        );

        if (empty($indexExists)) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->index(['item_id', 'warehouse_id']);
            });
        }
    }

    public function down(): void
    {
        $indexExists = DB::select(
            "SHOW INDEX FROM inventories WHERE Key_name = 'inventories_item_id_warehouse_id_index'"
        );

        if (!empty($indexExists)) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->dropIndex(['item_id', 'warehouse_id']);
            });
        }
    }
};
