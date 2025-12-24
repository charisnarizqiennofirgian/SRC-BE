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

class UmumStockImport implements ToModel, WithHeadingRow, WithValidation
{
    use Importable;

    public function model(array $row)
    {
        $category = Category::firstOrCreate(
            ['name' => $row['kategori']],
            [
                'type'        => 'umum',
                'description' => 'Kategori untuk barang umum',
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

        $stokAwal = (float) ($row['stok_awal'] ?? 0);

        $item = Item::firstOrCreate(
            ['code' => $row['kode']],
            [
                'name'        => $row['nama'],
                'category_id' => $category->id,
                'unit_id'     => $unit->id,
                'type'        => 'um',
                'uuid'        => Str::uuid(),
            ]
        );

        // stok awal SELALU diset sesuai Excel (boleh 0)
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
            'stok_awal' => 'required|numeric|min:0',
        ];
    }
}
