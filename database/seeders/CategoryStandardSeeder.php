<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategoryStandardSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Kayu Log',
            'Kayu RST',
            'Kayu Moulding / S4S',
            'Komponen',
            'Barang Mentah',
            'Produk Jadi',
            'Bahan Operasional',
            'Bahan Penolong',
            'Karton Box',
        ];

        foreach ($categories as $name) {
            Category::updateOrCreate(
                ['name' => $name],
                []
            );
        }
    }
}
