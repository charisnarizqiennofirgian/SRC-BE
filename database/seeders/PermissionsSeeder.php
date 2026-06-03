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

        // Tidak hapus semua — hanya tambah yang belum ada

        $permissions = [
            // Dashboard
            'view-dashboard',

            // Master Data
            'master-kategori',
            'master-satuan',
            'master-barang',
            'master-supplier',
            'master-buyer',
            'master-coa',
            'master-metode-pembayaran',

            // Keuangan
            'keuangan-jurnal-umum',
            'keuangan-opening-balance',
            'keuangan-buku-besar',
            'keuangan-laba-rugi',
            'keuangan-pembayaran-hutang',
            'keuangan-riwayat-pembayaran',
            'keuangan-neraca',

            // Manajemen Stok
            'stok-laporan-sawmill',
            'stok-index',
            'stok-adjustment',
            'stok-laporan-mutasi',
            'stok-monitoring-produksi',

            // Produksi
            'produksi-sawmill',
            'produksi-kd',
            'produksi-pembahanan',
            'produksi-moulding',
            'produksi-mesin',
            'produksi-rustik-komponen',
            'produksi-assembling',
            'produksi-pemakaian-bahan',
            'produksi-sanding',
            'produksi-rustik',
            'produksi-finishing',
            'produksi-anyam',
            'produksi-sampel-sawmill',
            'produksi-sampel-kd',
            'produksi-sampel-pembahanan',
            'produksi-sampel-moulding',
            'produksi-sampel-prototype',
            'produksi-sampel-sanding',
            'produksi-sampel-packing',
            'produksi-qc-final',
            'produksi-packing',
            'produksi-master-bom',

            // Pembelian
            'pembelian-purchase-request',
            'pembelian-operasional',
            'pembelian-karton',
            'pembelian-kayu',
            'pembelian-faktur',
            'pembelian-laporan-harga',

            // Penjualan
            'penjualan-so',
            'penjualan-uang-muka',
            'penjualan-pengiriman',
            'penjualan-invoice',

            // Perbaikan
            'perbaikan-jurnal-manual',

            // System
            'manage-users',
            'manage-roles',
            
            // Dokumen
            'dokumen-lihat',
            'dokumen-upload',
            'dokumen-hapus',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Super Admin dapat semua permission
        $superAdminRole = Role::firstOrCreate(
            ['name' => 'super-admin'],
            ['guard_name' => 'web']
        );
        $superAdminRole->syncPermissions(Permission::all());

        // Buat user admin
        $user = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
            ]
        );

        if (!$user->hasRole('super-admin')) {
            $user->assignRole($superAdminRole);
        }
    }
}