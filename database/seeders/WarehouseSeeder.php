<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Warehouse;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['name' => 'Gudang Log (Hulu)',              'code' => 'LOG'],
            ['name' => 'Gudang Sanwil (RST Basah)',      'code' => 'SANWIL'],
            ['name' => 'Gudang Candy (RST Kering)',      'code' => 'CANDY'],
            ['name' => 'Gudang Pembahanan (Transit RST)','code' => 'PEMBAHANAN'],
            ['name' => 'Gudang Moulding (Kayu S4S)',     'code' => 'MOULDING'],
            ['name' => 'Gudang Mesin (Komponen)',        'code' => 'MESIN'],
            ['name' => 'Gudang Assembling (Barang Mentah/Unfinished)','code' => 'ASSEMBLING'],
            ['name' => 'Gudang Finishing (Proses Warna)','code' => 'FINISHING'],
            ['name' => 'Gudang Packing (Barang Jadi/Siap Kirim)','code' => 'PACKING'],
            ['name' => 'Gudang Bahan (Lem, Paku, dll)',  'code' => 'BAHAN'],
        ];

        foreach ($data as $row) {
            Warehouse::firstOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name']]
            );
        }
    }
}
