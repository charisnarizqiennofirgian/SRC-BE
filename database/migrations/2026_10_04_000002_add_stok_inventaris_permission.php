<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(
            ['name' => 'stok-inventaris', 'guard_name' => 'web']
        );

        $role = Role::where('name', 'super-admin')->where('guard_name', 'web')->first();
        if ($role && !$role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::where('name', 'stok-inventaris')->where('guard_name', 'web')->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
