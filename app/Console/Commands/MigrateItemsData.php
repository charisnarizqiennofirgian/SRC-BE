<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Models\Material;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

class MigrateItemsData extends Command
{
    protected $signature = 'app:migrate-items';
    protected $description = 'Migrate data from products and materials tables to the new items table';

    public function handle()
    {
        $this->info('Memulai proses migrasi data ke tabel items...');

        DB::transaction(function () {
            // 1. Migrasi data dari tabel Products
            $products = Product::all();
            foreach ($products as $product) {
                Item::create([
                    'name' => $product->name,
                    'code' => $product->code,
                    'category_id' => $product->category_id,
                    'unit_id' => $product->unit_id,
                    'stock' => $product->stock,
                    'description' => $product->description,
                ]);
            }
            $this->info(count($products) . ' data Produk Jadi berhasil dimigrasi.');

            // 2. Migrasi data dari tabel Materials
            $materials = Material::all();
            foreach ($materials as $material) {
                Item::create([
                    'name' => $material->name,
                    'code' => $material->code,
                    'category_id' => $material->material_category_id, // Perhatikan nama kolom ini
                    'unit_id' => $material->unit_id,
                    'stock' => $material->stock,
                    'description' => $material->description,
                ]);
            }
            $this->info(count($materials) . ' data Bahan Baku berhasil dimigrasi.');
        });

        $this->info('Migrasi data selesai dengan sukses!');
        return 0;
    }
}