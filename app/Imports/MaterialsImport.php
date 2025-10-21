<?php

namespace App\Imports;

use App\Models\Item;
use App\Models\Category;
use App\Models\Unit;
use App\Models\StockMovement; 
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Illuminate\Support\Facades\DB; // 

class MaterialsImport implements ToCollection, WithHeadingRow, WithValidation
{
    
    public function collection(Collection $rows)
    {
        
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                
                $category = Category::firstOrCreate(['name' => $row['kategori']]);

                
                $unit = Unit::firstOrCreate(
                    ['name' => $row['satuan']],
                    ['short_name' => $row['satuan']] 
                );

                
                $item = Item::create([
                    'code'        => $row['kode'],
                    'name'        => $row['nama'],
                    'category_id' => $category->id,
                    'unit_id'     => $unit->id,
                    'description' => $row['deskripsi'] ?? null,
                    'type'        => $row['tipe'] ?? 'Stok',
                    'stock'       => 0, // Stok awal selalu nol
                ]);

                
                $stokAwal = $row['stok_awal'] ?? 0;
                if ($stokAwal > 0) {
                    StockMovement::create([
                        'item_id'   => $item->id,
                        'type'      => 'Stok Awal (Import)',
                        'quantity'  => $stokAwal,
                        'notes'     => 'Stok awal dari impor file Excel.',
                    ]);

                    
                    $item->stock = $stokAwal;
                    $item->save();
                }
            }
        });
    }
    
    
    public function rules(): array
    {
        return [
            'kode' => 'required|unique:items,code', // Pastikan validasi ke tabel 'items'
            'nama' => 'required',
            'kategori' => 'required',
            'satuan' => 'required',
            'deskripsi' => 'nullable',
            'stok_awal' => 'nullable|numeric|min:0',
            'tipe' => 'nullable|in:Stok,Non-Stok',
        ];
    }
}