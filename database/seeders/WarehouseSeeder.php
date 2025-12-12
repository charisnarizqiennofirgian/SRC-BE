<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Warehouse;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $warehouses = [
            ['name' => 'Gudang Log',                     'code' => 'LOG'],
            ['name' => 'Gudang Sanwil (RST Basah)',      'code' => 'RSTB'],
            ['name' => 'Gudang Candy (RST Kering)',      'code' => 'RSTK'],
            ['name' => 'Gudang Pembahanan (Buffer RST)', 'code' => 'BUFFER'],
            ['name' => 'Gudang Moulding (S4S)',          'code' => 'S4S'],
            ['name' => 'Gudang Mesin (Komponen)',        'code' => 'MESIN'],
            ['name' => 'Gudang Assembling (Barang Mentah)','code' => 'ASSEMBLING'],
            ['name' => 'Gudang Rustik',                  'code' => 'RUSTIK'],
            ['name' => 'Gudang Sanding (Seding)',        'code' => 'SANDING'],
            ['name' => 'Gudang Finishing (Warna)',       'code' => 'FINISHING'],
            ['name' => 'Gudang Packing (Barang Jadi)',   'code' => 'PACKING'],
        ];

        foreach ($warehouses as $data) {
            Warehouse::updateOrCreate(
                ['code' => $data['code']],
                ['name' => $data['name']]
            );
        }
    }
}
