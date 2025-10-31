<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
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
            Permission::create(['name' => $permission]);
        }

        
        $superAdminRole = Role::create(['name' => 'super-admin']);
        
        
        $superAdminRole->givePermissionTo(Permission::all());
        
        // 6. Buat Role "Admin" biasa (jika perlu)
        // $adminRole = Role::create(['name' => 'admin']);
        // $adminRole->givePermissionTo(['view-dashboard', 'manage-items']); 

        
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@admin.com',
            'password' => Hash::make('password')
        ]);

        
        $user->assignRole($superAdminRole);
    }
}