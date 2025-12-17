<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view-dashboard',
            'manage-users',
            'manage-roles',
            'manage-categories',
            'manage-units',
            'manage-suppliers',
            'manage-buyers',
            'manage-items',
            'manage-stock-adjustments',
            'view-stock-report',
            'manage-po',
            'manage-grn',
            'manage-bills',
            'manage-so',
            'manage-do',
            'manage-invoices',
            'manage-bom',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission],
                ['guard_name' => 'web']
            );
        }

        $superAdminRole = Role::firstOrCreate(
            ['name' => 'super-admin'],
            ['guard_name' => 'web']
        );

        $superAdminRole->syncPermissions(Permission::all());

        $user = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
            ]
        );

        if (! $user->hasRole('super-admin')) {
            $user->assignRole($superAdminRole);
        }
    }
}
