<?php

namespace App\Imports;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

class ProdukJadiStockImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{
    private $categoryProdukJadi;
    private $unitPieces;

    public function __construct()
    {
        $this->categoryProdukJadi = Category::firstOrCreate(
            ['name' => 'Produk Jadi'],
            ['description' => 'Produk Jadi Furniture']
        );
        
        $this->unitPieces = Unit::firstOrCreate(
            ['name' => 'Pieces'],
            ['short_name' => 'PCS']
        );
    }

    public function collection(Collection $rows)
    {
        set_time_limit(300);         
        ini_set('memory_limit', '512M'); 

        $userId = Auth::id();
        $skippedRows = [];

        foreach ($rows as $index => $row) 
        {
            if (empty($row['nama_produk']) || empty($row['stok_awal'])) {
                $skippedRows[] = $index + 2; 
                continue;
            }

            $namaProduk = trim($row['nama_produk']);
            $kodeBarang = trim($row['kode_barang']);
            $stokAwal = (float) $row['stok_awal'];

          
            $item = Item::updateOrCreate(
                ['code' => $kodeBarang],
                [
                    'name' => $namaProduk,
                    'category_id' => $this->categoryProdukJadi->id,
                    'unit_id' => $this->unitPieces->id,
                    'nw_per_box' => !empty($row['nw_per_box']) ? (float) $row['nw_per_box'] : null,
                    'gw_per_box' => !empty($row['gw_per_box']) ? (float) $row['gw_per_box'] : null,
                    'wood_consumed_per_pcs' => !empty($row['wood_consumed_per_pcs']) ? (float) $row['wood_consumed_per_pcs'] : null,
                    'm3_per_carton' => !empty($row['m3_per_carton']) ? (float) $row['m3_per_carton'] : null,
                ]
            );

            // Update Stok
            if ($stokAwal > 0) {
                $existingSaldoAwal = StockMovement::where('item_id', $item->id)
                                                  ->where('type', 'Saldo Awal')
                                                  ->first();

                $oldQuantity = 0;

                if ($existingSaldoAwal) {
                    $oldQuantity = $existingSaldoAwal->quantity;
                    $existingSaldoAwal->update([
                        'quantity' => $stokAwal,
                        'notes' => 'Saldo Awal Produk Jadi diperbarui via Excel upload.',
                    ]);
                } else {
                    StockMovement::create([
                        'item_id' => $item->id,
                        'type' => 'Saldo Awal',
                        'quantity' => $stokAwal,
                        'notes' => 'Saldo Awal Produk Jadi dari Excel upload.',
                    ]);
                }

                $quantityDifference = $stokAwal - $oldQuantity;
                
                $itemToUpdate = Item::lockForUpdate()->find($item->id);
                $itemToUpdate->increment('stock', $quantityDifference);
            }
        }

        if (!empty($skippedRows)) {
            Log::warning('Baris Excel Produk Jadi yang di-skip karena kolom nama_produk atau stok_awal kosong: ', $skippedRows);
        }
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';'
        ];
    }
}
