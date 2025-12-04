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
    public function collection(Collection $rows)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $skippedRows = [];
        $processedRows = 0;

        DB::transaction(function () use ($rows, &$skippedRows, &$processedRows) {
            foreach ($rows as $index => $row) {
                try {
                    // ✅ VALIDASI WAJIB
                    if (empty($row['kode']) || empty($row['nama']) || empty($row['kategori']) || empty($row['satuan'])) {
                        $skippedRows[] = [
                            'row_number' => $index + 2,
                            'reason' => 'Kolom kode, nama, kategori, atau satuan kosong'
                        ];
                        continue;
                    }

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
                    $stokBaru = isset($row['stok_awal']) ? (float) $row['stok_awal'] : 0;

                    $item->name = $row['nama'];
                    $item->category_id = $category->id;
                    $item->unit_id = $unit->id;
                    $item->description = $row['deskripsi'] ?? null;
                    $item->stock = $stokBaru;

                    $lowerCategoryName = strtolower($category->name);

                    // ✅ HANDLE KARTON BOX & KAYU RST
                    if (str_contains($lowerCategoryName, 'karton box') || str_contains($lowerCategoryName, 'kayu rst')) {
                        $item->specifications = [
                            'p' => isset($row['spec_p']) ? (float) $row['spec_p'] : null,
                            'l' => isset($row['spec_l']) ? (float) $row['spec_l'] : null,
                            't' => isset($row['spec_t']) ? (float) $row['spec_t'] : null,
                        ];
                        $item->nw_per_box = null;
                        $item->gw_per_box = null;
                        $item->wood_consumed_per_pcs = null;
                        $item->m3_per_carton = null;
                        $item->hs_code = null;
                    } 
                    // ✅ HANDLE PRODUK JADI
                    elseif (str_contains($lowerCategoryName, 'produk jadi')) {
                        // ✅ VALIDASI HS CODE WAJIB
                        if (empty($row['hs_code'])) {
                            $skippedRows[] = [
                                'row_number' => $index + 2,
                                'item_name' => $row['nama'],
                                'reason' => 'HS Code wajib diisi untuk Produk Jadi'
                            ];
                            continue;
                        }

                        $item->specifications = null;
                        $item->nw_per_box = isset($row['nw_per_box']) ? (float) $row['nw_per_box'] : null;
                        $item->gw_per_box = isset($row['gw_per_box']) ? (float) $row['gw_per_box'] : null;
                        $item->wood_consumed_per_pcs = isset($row['wood_consumed_per_pcs']) ? (float) $row['wood_consumed_per_pcs'] : null;
                        $item->m3_per_carton = isset($row['m3_per_carton']) ? (float) $row['m3_per_carton'] : null;
                        $item->hs_code = trim($row['hs_code']);
                    } 
                    // ✅ KATEGORI LAINNYA
                    else {
                        $item->specifications = null;
                        $item->nw_per_box = null;
                        $item->gw_per_box = null;
                        $item->wood_consumed_per_pcs = null;
                        $item->m3_per_carton = null;
                        $item->hs_code = null;
                    }

                    $item->save();

                    // ✅ BIKIN STOCK MOVEMENT (Type: Stok Masuk atau Stok Keluar)
                    if ((float)$stokBaru !== (float)$stokLama) {
                        $selisih = (float)$stokBaru - (float)$stokLama;
                        
                        // ✅ Tentukan type berdasarkan selisih
                        $movementType = $selisih > 0 ? 'Stok Masuk' : 'Stok Keluar';
                        
                        StockMovement::create([
                            'item_id'  => $item->id,
                            'type'     => $movementType, // ✅ FIX: Pakai 'Stok Masuk' atau 'Stok Keluar'
                            'quantity' => $selisih, // Bisa positif atau negatif
                            'notes'    => "Import Excel: Stok berubah dari {$stokLama} menjadi {$stokBaru}",
                        ]);
                    }

                    $processedRows++;
                } catch (\Exception $e) {
                    $skippedRows[] = [
                        'row_number' => $index + 2,
                        'item_name' => $row['nama'] ?? 'Unknown',
                        'reason' => 'Error sistem: ' . $e->getMessage()
                    ];
                    Log::error("Error processing material import row {$index}: " . $e->getMessage());
                }
            }
        });

        if (!empty($skippedRows)) {
            Log::warning('Baris Excel Material yang ditolak:', $skippedRows);
        }
        
        Log::info("Import Material selesai. Berhasil: {$processedRows} baris. Ditolak: " . count($skippedRows) . " baris.");
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
            'm3_per_carton' => 'nullable|numeric|min:0', // ✅ TAMBAH
            'hs_code' => 'nullable|string|max:50', // ✅ TAMBAH
        ];
    }
}
