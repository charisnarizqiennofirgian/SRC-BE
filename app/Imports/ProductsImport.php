<?php

namespace App\Imports;

use App\Models\Item; // <-- GANTI: Gunakan model Item
use App\Models\Category;
use App\Models\Unit;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ProductsImport implements ToModel, WithHeadingRow, WithValidation
{
    private $productCategoryId;
    private $units;

    /**
     * Constructor ini akan berjalan sekali saja sebelum impor dimulai.
     * Kita siapkan data yang dibutuhkan agar lebih efisien.
     */
    public function __construct()
    {
        // 1. Cari dan simpan ID untuk kategori "Produk Jadi"
        $this->productCategoryId = Category::where('name', 'Produk Jadi')->value('id');
        
        // 2. Ambil semua data unit untuk pencocokan nama
        // Logika cerdas Anda untuk case-insensitive kita pertahankan
        $this->units = Unit::pluck('id', 'name')->mapWithKeys(function ($id, $name) {
            return [strtolower(trim($name)) => $id];
        });
    }

    /**
     * Fungsi ini akan berjalan untuk setiap baris di file Excel.
     * @param array $row
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        
        $unitName = strtolower(trim($row['satuan']));
        $unitId = $this->units[$unitName] ?? null;

        
        if ($unitId && $this->productCategoryId) {
            return new Item([ // 
                'code'        => $row['kode'],
                'name'        => $row['nama_produk'],
                'description' => $row['deskripsi'] ?? null,
                'stock'       => $row['stok'] ?? 0,
                'price'       => $row['harga'] ?? 0,
                'category_id' => $this->productCategoryId, // 
                'unit_id'     => $unitId,
            ]);
        }

        // Jika satuan tidak ditemukan, lewati baris ini
        return null;
    }

    /**
     * Aturan validasi untuk setiap baris di Excel.
     */
    public function rules(): array
    {
        return [
            'kode' => 'required|string|unique:items,code', // 
            'nama_produk' => 'required|string',
            'satuan' => 'required|string|exists:units,name', // 
        ];
    }
}