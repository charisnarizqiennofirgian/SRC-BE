<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Product;
use App\Models\Material;
use App\Models\StockAdjustment;

class StockAdjustmentImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
               
                if (empty($row['kode_barang']) || !isset($row['jumlah_stok_baru'])) {
                    continue;
                }

                $item = null;
                $modelClass = null;
                $quantity = (float) $row['jumlah_stok_baru'];

                
                $item = Product::where('code', $row['kode_barang'])->first();
                if ($item) {
                    $modelClass = Product::class;
                } else {
                    
                    $item = Material::where('code', $row['kode_barang'])->first();
                    if ($item) {
                        $modelClass = Material::class;
                    }
                }

              
                if ($item && $modelClass) {
                    
                    $quantityDifference = $quantity - $item->stock;

                    
                    $item->stock = $quantity;
                    $item->save();

                    
                    StockAdjustment::create([
                        'adjustable_id' => $item->id,
                        'adjustable_type' => $modelClass,
                        'type' => 'Stok Awal (Upload)',
                        'quantity' => $quantityDifference, // Catat selisihnya
                        'notes' => 'Stok awal diatur via upload Excel.',
                        'user_id' => Auth::id(),
                    ]);
                }
                
            }
        });
    }
}