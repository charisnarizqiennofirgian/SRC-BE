<?php

namespace App\Imports;

use App\Models\Item;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\Stock;
use App\Models\Inventory;
use App\Models\InventoryLog;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

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

            $warehouseCode = strtoupper(trim($gudangCode)); // mis: LOG

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

                Log::info("ROW #{$index} - ITEM SAVED: {$name} (ID: {$item->id})");

                // 2. Gudang
                $warehouse = Warehouse::whereRaw('UPPER(code) = ?', [$warehouseCode])->first();

                if (!$warehouse) {
                    Log::warning('Gudang tidak ditemukan untuk Kayu Log', [
                        'row'          => $index + 2,
                        'kode_item'    => $code,
                        'gudang_excel' => $warehouseCode,
                    ]);
                    continue;
                }

                // 3. Stok per gudang (legacy - tabel stocks)
                Stock::updateOrCreate(
                    [
                        'item_id'      => $item->id,
                        'warehouse_id' => $warehouse->id,
                    ],
                    [
                        'quantity' => $qtyBatang,
                    ]
                );

                Log::info("ROW #{$index} - STOCK GUDANG OK (WH ID {$warehouse->id})");

                // ✅ 4. Update tabel inventories
                Inventory::updateOrCreate(
                    [
                        'item_id'      => $item->id,
                        'warehouse_id' => $warehouse->id,
                    ],
                    [
                        'qty'    => $qtyBatang,
                        'qty_m3' => $kubikasi,
                    ]
                );

                Log::info("ROW #{$index} - ✅ INVENTORY SAVED: {$qtyBatang} batang, {$kubikasi} m³");

                // ✅ 5. Catat ke inventory_logs
                $existingLog = InventoryLog::where('item_id', $item->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->where('transaction_type', 'INITIAL_STOCK')
                    ->first();

                if ($existingLog) {
                    $existingLog->update([
                        'qty'    => $qtyBatang,
                        'qty_m3' => $kubikasi,
                        'notes'  => 'Saldo Awal Kayu Log diperbarui via Excel',
                    ]);
                    Log::info("ROW #{$index} - ✅ INVENTORY_LOG UPDATED");
                } else {
                    InventoryLog::create([
                        'date'             => now()->toDateString(),
                        'time'             => now()->toTimeString(),
                        'item_id'          => $item->id,
                        'warehouse_id'     => $warehouse->id,
                        'qty'              => $qtyBatang,
                        'qty_m3'           => $kubikasi,
                        'direction'        => 'IN',
                        'transaction_type' => 'INITIAL_STOCK',
                        'reference_type'   => 'ImportExcel',
                        'reference_id'     => $item->id,
                        'reference_number' => 'IMPORT-LOG-' . $code,
                        'notes'            => 'Saldo Awal Kayu Log dari Excel upload',
                        'user_id'          => Auth::id(),
                    ]);
                    Log::info("ROW #{$index} - ✅ INVENTORY_LOG CREATED");
                }

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
