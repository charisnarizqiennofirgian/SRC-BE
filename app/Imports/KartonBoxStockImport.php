<?php

namespace App\Imports;

use App\Models\Item;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\Importable;

class KartonBoxStockImport implements ToModel, WithHeadingRow, WithValidation
{
    use Importable;

    public function model(array $row)
    {
        $category = Category::firstOrCreate(
            ['name' => $row['kategori']],
            [
                'type'        => 'karton',
                'description' => 'Kategori untuk Karton Box',
                'created_by'  => 1,
            ]
        );

        $unit = Unit::firstOrCreate(
            ['name' => $row['satuan']],
            [
                'symbol'      => $row['satuan'],
                'description' => 'Satuan untuk ' . $row['satuan'],
            ]
        );

        $p = (float) ($row['p'] ?? 0);
        $l = (float) ($row['l'] ?? 0);
        $t = (float) ($row['t'] ?? 0);
        $stokAwal = (float) ($row['stok_awal'] ?? 0);

        $m3PerPcs = 0;
        if ($p > 0 && $l > 0 && $t > 0) {
            $m3PerPcs = ($p * $l * $t) / 1_000_000_000;
        }

        $specifications = [
            'p'          => $p,
            'l'          => $l,
            't'          => $t,
            'm3_per_pcs' => $m3PerPcs,
        ];

        $item = Item::firstOrCreate(
            ['code' => $row['kode']],
            [
                'name'        => $row['nama'],
                'category_id' => $category->id,
                'unit_id'     => $unit->id,
                'type'        => 'kb',
                'uuid'        => Str::uuid(),
            ]
        );

        $item->specifications = $specifications;
        $item->stock = $stokAwal;
        $item->save();

        return $item;
    }

    public function rules(): array
    {
        return [
            'kode'      => 'required|string|max:255',
            'nama'      => 'required|string|max:255',
            'kategori'  => 'required|string|max:255',
            'satuan'    => 'required|string|max:255',
            'p'         => 'required|numeric|min:0',
            'l'         => 'required|numeric|min:0',
            't'         => 'required|numeric|min:0',
            'stok_awal' => 'required|numeric|min:0',
        ];
    }
}
