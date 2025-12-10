<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Item;
use App\Models\Category;
use App\Models\Warehouse;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;

class MigrateOldStockSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // Ambil kategori yang relevan
            $mapCategoryToWarehouseCode = [
                'Kayu Log'          => 'LOG',
                'Kayu RST'          => 'SANWIL',
                'Produk Jadi'       => 'PACKING',
                'Bahan Operasional' => 'BAHAN',
            ];

            // Ambil semua kategori tersebut
            $categories = Category::whereIn('name', array_keys($mapCategoryToWarehouseCode))
                ->get()
                ->keyBy('name');

            foreach ($mapCategoryToWarehouseCode as $catName => $whCode) {
                $category = $categories->get($catName);
                if (!$category) {
                    continue;
                }

                $warehouse = Warehouse::where('code', $whCode)->first();
                if (!$warehouse) {
                    continue;
                }

                // Ambil semua item di kategori ini yang stok-nya > 0
                $items = Item::where('category_id', $category->id)
                    ->where('stock', '>', 0)
                    ->get();

                foreach ($items as $item) {
                    Stock::updateOrCreate(
                        [
                            'item_id'      => $item->id,
                            'warehouse_id' => $warehouse->id,
                        ],
                        [
                            'quantity' => $item->stock,
                        ]
                    );
                }
            }
        });
    }
}
