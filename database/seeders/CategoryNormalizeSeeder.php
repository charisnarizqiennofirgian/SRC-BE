<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategoryNormalizeSeeder extends Seeder
{
    public function run(): void
    {
        $targets = [
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

        foreach ($targets as $name) {
            Category::firstOrCreate(['name' => $name]);
        }

        $mapping = [
            'PRODUK JADI'          => 'Produk Jadi',
            'Produk Jadi'          => 'Produk Jadi',
            'BAHAN BAKU'           => 'Barang Mentah',
            'Barang Setengah Jadi' => 'Barang Mentah',
            'BAHAN PENOLONG'       => 'Bahan Penolong',
            'BAHAN OPERASIONAL'    => 'Bahan Operasional',
            'KARTON BOX'           => 'Karton Box',
            'Karton Box'           => 'Karton Box',
            'Kayu RST'             => 'Kayu RST',
            'Kayu Log'             => 'Kayu Log',
            'Kayu Moulding'        => 'Kayu Moulding / S4S',
        ];

        foreach ($mapping as $old => $new) {
            $target = Category::where('name', $new)->first();

            if ($target) {
                // kalau ada kategori lama bernama $old
                Category::where('name', $old)
                    ->where('id', '!=', $target->id)
                    ->get()
                    ->each(function ($cat) use ($target) {
                        
                        \App\Models\Item::where('category_id', $cat->id)
                            ->update(['category_id' => $target->id]);
                        
                        $cat->delete();
                    });
            }
        }
    }
}
