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

class KayuStockImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{
    private $categoryKayu;
    private $unitPieces;

    public function __construct()
    {
        $this->categoryKayu = Category::firstOrCreate(
            ['name' => 'Kayu RST'],
            ['description' => 'Bahan Baku Kayu RST']
        );
        
        $this->unitPieces = Unit::firstOrCreate(
            ['name' => 'Pieces'],
            ['short_name' => 'PCS']
        );
    }

    public function collection(Collection $rows)
    {
        $userId = Auth::id();

        foreach ($rows as $row) 
        {
            if (empty($row['nama_dasar']) || empty($row['tebal_mm']) || empty($row['stok_awal'])) {
                continue;
            }

            $namaDasar = trim($row['nama_dasar']);
            $kodeBarang = trim($row['kode_barang']);
            $t = (float) $row['tebal_mm'];
            $l = (float) $row['lebar_mm'];
            $p = (float) $row['panjang_mm'];
            $stokAwal = (float) $row['stok_awal'];

            $uniqueName = "{$namaDasar} ({$t}x{$l}x{$p})";
            $kubikasi = ($t * $l * $p) / 1000000000;

            $specifications = [
                't' => $t,
                'l' => $l,
                'p' => $p,
                'm3_per_pcs' => $kubikasi
            ];

            $item = Item::updateOrCreate(
                [
                    'name' => $uniqueName
                ],
                [
                    'code' => $kodeBarang,
                    'category_id' => $this->categoryKayu->id,
                    'unit_id' => $this->unitPieces->id,
                    'specifications' => $specifications,
                ]
            );

            if ($stokAwal > 0) {
                
                $existingSaldoAwal = StockMovement::where('item_id', $item->id)
                                                  ->where('type', 'Saldo Awal')
                                                  ->first();

                $oldQuantity = 0;

                if ($existingSaldoAwal) {
                    $oldQuantity = $existingSaldoAwal->quantity;
                    $existingSaldoAwal->update([
                        'quantity' => $stokAwal,
                        'notes'    => 'Saldo Awal (Kayu) diperbarui via Excel upload.',
                    ]);
                } else {
                    StockMovement::create([
                        'item_id'  => $item->id,
                        'type'     => 'Saldo Awal',
                        'quantity' => $stokAwal,
                        'notes'    => 'Saldo Awal (Kayu) dari Excel upload.',
                    ]);
                }

                $quantityDifference = $stokAwal - $oldQuantity;
                
                $itemToUpdate = Item::lockForUpdate()->find($item->id);
                $itemToUpdate->increment('stock', $quantityDifference);
            }
        }
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';'
        ];
    }
}