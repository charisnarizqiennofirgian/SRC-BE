<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class RoleAndUserSeeder extends Seeder
{
    public function run(): void
    {
        
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        
        Role::firstOrCreate(['name' => 'super-admin']);

        
        $user = User::firstOrCreate([
            'email' => 'admin@sbc.com'
        ], [
            'name' => 'admin',
            'password' => Hash::make('admin')
        ]);

        
        $user->assignRole('super-admin');
    }
}