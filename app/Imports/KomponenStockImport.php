<?php

namespace App\Imports;

use App\Models\Item;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\Stock;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\Importable;
use Illuminate\Support\Facades\Log;

class KomponenStockImport implements
    ToCollection,
    WithHeadingRow,
    WithValidation,
    WithCustomCsvSettings
{
    use Importable;

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            try {
                // Normalisasi string
                $kode     = trim((string) ($row['kode'] ?? ''));
                $nama     = trim((string) ($row['nama'] ?? ''));
                $kategori = trim((string) ($row['kategori'] ?? ''));
                $satuan   = trim((string) ($row['satuan'] ?? ''));
                $gudang   = trim((string) ($row['gudang'] ?? ''));

                if ($kode === '' || $nama === '') {
                    continue;
                }

                // Category: dari Excel (biasanya "Komponen")
                $category = Category::firstOrCreate(
                    ['name' => $kategori],
                    [
                        'description' => 'Kategori untuk Komponen',
                        'created_by'  => 1,
                    ]
                );

                // Unit: pakai name + short_name (sesuai model Unit)
                $unit = Unit::firstOrCreate(
                    ['name' => $satuan],
                    [
                        'short_name' => $satuan,
                    ]
                );

                // Warehouse: wajib dari kolom gudang (pakai code atau name, sesuaikan template)
                $warehouse = Warehouse::where('code', $gudang)
                    ->orWhere('name', $gudang)
                    ->first();

                if (! $warehouse) {
                    // Kalau gudang tidak ditemukan, skip baris + log
                    Log::warning(
                        'Gudang tidak ditemukan saat import Komponen di baris ' . ($index + 2),
                        ['gudang' => $gudang, 'row' => $row->toArray()]
                    );
                    continue;
                }

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
                    ['code' => $kode],
                    [
                        'name'        => $nama,
                        'category_id' => $category->id,
                        'unit_id'     => $unit->id,
                        'uuid'        => Str::uuid(),
                        'type'        => 'component',
                    ]
                );

                // Update specs + sinkron kategori/unit kalau ada perubahan
                $item->specifications = $specifications;
                $item->category_id    = $category->id;
                $item->unit_id        = $unit->id;
                $item->save();

                // Stok awal: catat ke tabel stocks per gudang
                if ($stokAwal > 0) {
                    $stock = Stock::firstOrCreate(
                        [
                            'item_id'      => $item->id,
                            'warehouse_id' => $warehouse->id,
                        ],
                        [
                            'quantity' => 0,
                        ]
                    );

                    $stock->quantity += $stokAwal;
                    $stock->save();
                }
            } catch (\Throwable $e) {
                Log::error(
                    'Error import Komponen di baris ' . ($index + 2) . ': ' . $e->getMessage(),
                    ['row_data' => $row->toArray()]
                );
            }
        }
    }

    public function rules(): array
    {
        return [
            'kode'      => 'required|string|max:255',
            'nama'      => 'required|string|max:255',
            'kategori'  => 'required|string|max:255',
            'satuan'    => 'required|string|max:255',
            'gudang'    => 'required|string|max:255',
            'p'         => 'required|numeric|min:0',
            'l'         => 'required|numeric|min:0',
            't'         => 'required|numeric|min:0',
            'stok_awal' => 'required|numeric|min:0',
        ];
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
        ];
    }
}
