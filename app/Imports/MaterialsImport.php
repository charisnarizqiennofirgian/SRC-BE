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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MaterialsImport implements ToCollection, WithHeadingRow, WithValidation
{
    /**
     * Logika utama untuk import.
     * Menggunakan firstOrNew -> save() agar lebih aman.
     */
    public function collection(Collection $rows)
    {
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                
                
                $category = Category::firstOrCreate(
                    ['name' => $row['kategori']],
                    ['name' => $row['kategori']]
                );

                
                $unit = Unit::firstOrCreate(
                    ['name' => $row['satuan']],
                    ['name' => $row['satuan'], 'short_name' => $row['satuan']] 
                );

                

                
                $item = Item::firstOrNew(['code' => $row['kode']]);

                
                
                $stokLama = $item->stock ?? 0;
                
                
                $stokBaru = $row['stok_awal'] ?? 0;

                
                $item->name = $row['nama'];
                $item->category_id = $category->id;
                $item->unit_id = $unit->id;
                $item->description = $row['deskripsi'] ?? null;
                $item->stock = $stokBaru; 
                
                
                $item->save(); 

                
                if ((float)$stokBaru != (float)$stokLama) {
                    $selisih = (float)$stokBaru - (float)$stokLama;
                    StockMovement::create([
                        'item_id'   => $item->id,
                        'type'      => 'Stok Awal (Import)',
                        'quantity'  => $selisih,
                        'notes'     => 'Stok Awal (Import) mengubah stok dari ' . $stokLama . ' menjadi ' . $stokBaru,
                    ]);
                }
            
            }
        });
    }
    
    
    // Validasi untuk setiap baris di Excel
    public function rules(): array
    {
        return [
            'kode' => 'required|string', // Hapus 'unique' agar bisa update
            'nama' => 'required',
            'kategori' => 'required|string',
            'satuan' => 'required|string',
            'deskripsi' => 'nullable',
            'stok_awal' => 'nullable|numeric|min:0',
        ];
    }
}