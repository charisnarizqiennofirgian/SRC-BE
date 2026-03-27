<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Machine;

class MachineSeeder extends Seeder
{
    public function run(): void
    {
        $machines = [
            ['name' => 'Wide Belt Sander (WBS)', 'code' => 'WBS',  'description' => 'Mesin amplas lebar untuk meratakan permukaan kayu'],
            ['name' => 'Circular Saw (Corcut)',   'code' => 'CRC',  'description' => 'Mesin gergaji potong komponen'],
            ['name' => 'Thicknesser / Planer',    'code' => 'THK',  'description' => 'Mesin serut untuk menyamakan ketebalan kayu'],
            ['name' => 'Spindle Moulder',          'code' => 'SPD',  'description' => 'Mesin profil dan bentuk kayu'],
            ['name' => 'Tenoner',                  'code' => 'TNO',  'description' => 'Mesin pembuat pen/tenon untuk sambungan kayu'],
            ['name' => 'Double Tenoner',           'code' => 'DTN',  'description' => 'Mesin pembuat 2 tenon sekaligus'],
            ['name' => 'Mortiser',                 'code' => 'MRT',  'description' => 'Mesin pembuat lubang pen/mortise'],
            ['name' => 'Router Atas',              'code' => 'RTA',  'description' => 'Mesin router posisi atas untuk alur dan profil'],
            ['name' => 'Bor Manual',               'code' => 'BRM',  'description' => 'Mesin bor manual untuk lubang dowel/sekrup'],
            ['name' => 'Bor CNC',                  'code' => 'BRC',  'description' => 'Mesin bor otomatis CNC presisi tinggi'],
            ['name' => 'Router',                   'code' => 'RTR',  'description' => 'Mesin router untuk alur dan profil kayu'],
            ['name' => 'Copy Lathe (Osi Lathe)',   'code' => 'OSL',  'description' => 'Mesin bubut kayu untuk komponen bulat/profil'],
            ['name' => 'CNC Router',               'code' => 'CNC',  'description' => 'Mesin CNC otomatis untuk pemotongan dan ukiran presisi'],
        ];

        foreach ($machines as $machine) {
            Machine::updateOrCreate(
                ['code' => $machine['code']],
                [
                    'name'        => $machine['name'],
                    'description' => $machine['description'],
                    'is_active'   => true,
                ]
            );
        }
    }
}