<?php

use App\Models\SalesOrder;
use App\Models\SalesOrderPrint;
use App\Services\SalesOrderPrintService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->string('action', 20);
            $table->string('content_hash', 64)->nullable();
            $table->string('details_signature', 50)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sales_order_id', 'id']);
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(
            ['name' => 'dokumen-so', 'guard_name' => 'web']
        );

        foreach (['Drafter', 'super-admin'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && !$role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $service = app(SalesOrderPrintService::class);
        SalesOrder::without('details')
            ->whereNotIn('status', ['Draft', 'Cancelled'])
            ->orderBy('id')
            ->each(function (SalesOrder $so) use ($service) {
                $service->record($so, SalesOrderPrint::ACTION_BASELINE, null);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_prints');

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::where('name', 'dokumen-so')->where('guard_name', 'web')->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
