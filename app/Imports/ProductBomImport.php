<?php

namespace App\Imports;

use App\Models\Item;
use App\Models\ProductBom;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Log;

class ProductBomImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        $skippedRows   = [];
        $processedRows = 0;

        Log::info('=== MULAI IMPORT BOM ===');

        foreach ($rows as $index => $row) {
            $rowData = $row->toArray();

            // Normalisasi key ke lower case
            $normalized = [];
            foreach ($rowData as $key => $value) {
                $normalized[strtolower(trim($key))] = $value;
            }

            $parentCode = trim($normalized['kode_produk_utama'] ?? '');
$childCode  = trim($normalized['kode_komponen'] ?? '');
$qty        = isset($normalized['jumlah_per_produk'])
                ? (float) $normalized['jumlah_per_produk']
                : 0;


            // Validasi minimal
            if ($parentCode === '' || $childCode === '' || $qty <= 0) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason'     => 'kode_induk / kode_komponen kosong atau qty_per_induk <= 0',
                    'data'       => $normalized,
                ];
                continue;
            }

            // Cari item induk & komponen berdasarkan code
            $parentItem = Item::where('code', $parentCode)->first();
            $childItem  = Item::where('code', $childCode)->first();

            if (!$parentItem || !$childItem) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason'     => 'Item induk/komponen tidak ditemukan di master items',
                    'data'       => $normalized,
                ];
                continue;
            }

            // Simpan / update BOM
            ProductBom::updateOrCreate(
                [
                    'parent_item_id' => $parentItem->id,
                    'child_item_id'  => $childItem->id,
                ],
                [
                    'qty' => $qty,
                ]
            );

            $processedRows++;
        }

        if (!empty($skippedRows)) {
            Log::warning('Baris BOM yang ditolak:', $skippedRows);
        }

        Log::info("=== IMPORT BOM SELESAI. Berhasil: {$processedRows}, Ditolak: " . count($skippedRows) . " ===");
    }
}
