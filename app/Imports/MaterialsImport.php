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

class MaterialsImport implements ToCollection, WithHeadingRow, WithValidation
{
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

                $lowerCategoryName = strtolower($category->name);

                if (str_contains($lowerCategoryName, 'karton box') || str_contains($lowerCategoryName, 'kayu rst')) {
                    $item->specifications = [
                        'p' => $row['spec_p'] ?? null,
                        'l' => $row['spec_l'] ?? null,
                        't' => $row['spec_t'] ?? null,
                    ];
                    $item->nw_per_box = null;
                    $item->gw_per_box = null;
                    $item->wood_consumed_per_pcs = null;
                } elseif (str_contains($lowerCategoryName, 'produk jadi')) {
                    $item->specifications = null;
                    $item->nw_per_box = $row['nw_per_box'] ?? null;
                    $item->gw_per_box = $row['gw_per_box'] ?? null;
                    $item->wood_consumed_per_pcs = $row['wood_consumed_per_pcs'] ?? null;
                } else {
                    $item->specifications = null;
                    $item->nw_per_box = null;
                    $item->gw_per_box = null;
                    $item->wood_consumed_per_pcs = null;
                }

                $item->save();

                if ((float)$stokBaru !== (float)$stokLama) {
                    $selisih = (float)$stokBaru - (float)$stokLama;
                    StockMovement::create([
                        'item_id'  => $item->id,
                        'type'     => 'Stok Awal (Import)',
                        'quantity' => $selisih,
                        'notes'    => 'Stok Awal (Import) mengubah stok dari ' . $stokLama . ' menjadi ' . $stokBaru,
                    ]);
                }
            }
        });
    }

    public function rules(): array
    {
        return [
            'kode' => 'required|string',
            'nama' => 'required',
            'kategori' => 'required|string',
            'satuan' => 'required|string',
            'deskripsi' => 'nullable',
            'stok_awal' => 'nullable|numeric|min:0',
            'spec_p' => 'nullable|numeric|min:0',
            'spec_l' => 'nullable|numeric|min:0',
            'spec_t' => 'nullable|numeric|min:0',
            'nw_per_box' => 'nullable|numeric|min:0',
            'gw_per_box' => 'nullable|numeric|min:0',
            'wood_consumed_per_pcs' => 'nullable|numeric|min:0',
        ];
    }
}
