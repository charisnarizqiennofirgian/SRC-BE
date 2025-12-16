<?php

namespace App\Imports;

use App\Models\Item;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\Stock;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Illuminate\Support\Facades\Log;

class KayuLogStockImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            // Wajib
            $kodeItem   = $row['kode_item']   ?? null;
            $namaItem   = $row['nama_item']   ?? null;
            $gudangCode = $row['gudang']      ?? null;
            $qtyBatang  = $row['qty_batang']  ?? null;

            if (
                empty($kodeItem) ||
                empty($namaItem) ||
                empty($gudangCode) ||
                $qtyBatang === null
            ) {
                Log::warning('Row Kayu Log dilewati karena kolom wajib kosong', [
                    'row'  => $index + 2,
                    'data' => $row->toArray(),
                ]);
                continue;
            }

            $code      = trim($kodeItem);
            $name      = trim($namaItem);
            $qtyBatang = (float) $qtyBatang;

            $warehouseCode = trim($gudangCode); // mis: LOG

            // Dari master
            $kategoriName = trim($row['kategori'] ?? 'Kayu Log');
            $satuanName   = trim($row['satuan']   ?? 'Batang');

            $category = Category::firstOrCreate(
                ['name' => $kategoriName]
            );

            $unit = Unit::firstOrCreate(
                ['name' => $satuanName],
                ['short_name' => strtoupper(substr($satuanName, 0, 5))]
            );

            $diameter  = (float) ($row['diameter_cm'] ?? 0);
            $panjang   = (float) ($row['panjang_cm'] ?? 0);
            $jenisKayu = trim($row['jenis_kayu'] ?? '');
            $kubikasi  = (float) ($row['kubikasi_m3'] ?? 0);

            $specs = [
                'diameter_cm' => $diameter,
                'panjang_cm'  => $panjang,
                'jenis_kayu'  => $jenisKayu,
            ];

            try {
                // 1. Master item
                $item = Item::updateOrCreate(
                    ['code' => $code],
                    [
                        'name'           => $name,
                        'category_id'    => $category->id,
                        'unit_id'        => $unit->id,
                        'specifications' => $specs,
                        'stock'          => $qtyBatang,
                        'volume_m3'      => $kubikasi,
                    ]
                );

                // 2. Gudang
                $warehouse = Warehouse::where('code', $warehouseCode)->first();

                if (!$warehouse) {
                    Log::warning('Gudang tidak ditemukan untuk Kayu Log', [
                        'row'          => $index + 2,
                        'kode_item'    => $code,
                        'gudang_excel' => $warehouseCode,
                    ]);
                    continue;
                }

                // 3. Stok per gudang
                Stock::updateOrCreate(
                    [
                        'item_id'      => $item->id,
                        'warehouse_id' => $warehouse->id,
                    ],
                    [
                        'quantity' => $qtyBatang,
                    ]
                );
            } catch (\Exception $e) {
                Log::error(
                    'Error import Kayu Log di baris ' . ($index + 2) . ': ' . $e->getMessage(),
                    ['row_data' => $row->toArray()]
                );
            }
        }
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
        ];
    }
}
