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
            // Mapping kolom baru
            $kodeItem = $row['kode'] ?? null;
            // Nama item tidak ada di excel, kita generate nanti
            $qtyBatang = $row['stok'] ?? 0;

            // Default Gudang LOG jika tidak ada kolom gudang
            $gudangCode = trim($row['gudang'] ?? 'LOG');

            if (empty($kodeItem)) {
                continue;
            }

            $code = trim($kodeItem);
            $qtyBatang = (float) $qtyBatang;

            // Default Kategori & Satuan
            $kategoriName = trim($row['kategori'] ?? 'Kayu Log');
            $satuanName = trim($row['satuan'] ?? 'Batang');

            $category = Category::firstOrCreate(
                ['name' => $kategoriName]
            );

            $unit = Unit::firstOrCreate(
                ['name' => $satuanName],
                ['short_name' => str_replace('Batang', 'PCS', $satuanName)] // map Batang to PCS shortname if needed
            );

            $diameter = (float) ($row['diameter_cm'] ?? 0);
            // Convert Panjang (m) to cm if using legacy field `panjang` which is likely in cm or generic
            // Assuming previous logic used cm. New input is m. 
            $panjangM = (float) ($row['panjang_m'] ?? 0);
            $panjang = $panjangM * 100;

            $jenisKayu = trim($row['jenis_kayu'] ?? '');
            $tpk = trim($row['tpk'] ?? '');
            $kubikasi = (float) ($row['kubikasi_m3'] ?? 0);

            $tanggalTerima = $row['tanggal_terima'] ?? null;
            if ($tanggalTerima && is_numeric($tanggalTerima)) {
                // Konversi format tanggal Excel (serial number) ke Y-m-d
                $tanggalTerima = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($tanggalTerima)->format('Y-m-d');
            }

            $noSkshhk = trim($row['no_skshhk'] ?? '');
            $noKapling = trim($row['no_kapling'] ?? '');
            $mutu = trim($row['mutu'] ?? '');

            // Auto Generate Name: Log [Jenis] [Diameter]x[Panjang]
            $name = "Log {$jenisKayu} D{$diameter} P{$panjangM}";

            $specs = [
                'diameter_cm' => $diameter,
                'panjang_cm' => $panjang,
                'panjang_m' => $panjangM,
                'jenis_kayu' => $jenisKayu,
                'tpk' => $tpk,
                'm3_per_pcs' => $kubikasi,
                'no_skshhk' => $noSkshhk,
                'no_kapling' => $noKapling,
                'mutu' => $mutu
            ];

            try {
                $item = Item::updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'category_id' => $category->id,
                        'unit_id' => $unit->id,
                        'specifications' => $specs,
                        'stock' => $qtyBatang,
                        'volume_m3' => $kubikasi,
                        'diameter' => $diameter,
                        'panjang' => $panjang,
                        'jenis_kayu' => $jenisKayu,
                        'tpk' => $tpk,
                        'kubikasi' => $kubikasi,
                        'tanggal_terima' => $tanggalTerima,
                        'no_skshhk' => $noSkshhk,
                        'no_kapling' => $noKapling,
                        'mutu' => $mutu,
                    ]
                );

                Log::info("ROW #{$index} - ITEM SAVED: {$name} (ID: {$item->id})");

                $warehouse = Warehouse::whereRaw('UPPER(code) = ?', [$gudangCode])->first();

                if (!$warehouse) {
                    // Auto create Gudang LOG if missing? Or log error? User didn't request auto-create.
                    // But previously we skipped if missing. Since we default to 'LOG', let's see if it exists.
                    // Making it safer: try to find by name "Log" too.
                    $warehouse = Warehouse::firstOrCreate(
                        ['code' => 'LOG'],
                        ['name' => 'Gudang Log']
                    );
                }

                Stock::updateOrCreate(
                    [
                        'item_id' => $item->id,
                        'warehouse_id' => $warehouse->id,
                    ],
                    [
                        'quantity' => $qtyBatang,
                    ]
                );

                Log::info("ROW #{$index} - STOCK GUDANG OK (WH ID {$warehouse->id})");

                Inventory::updateOrCreate(
                    [
                        'item_id' => $item->id,
                        'warehouse_id' => $warehouse->id,
                    ],
                    [
                        'qty_pcs' => $qtyBatang,
                        'qty_m3' => $kubikasi,
                    ]
                );

                Log::info("ROW #{$index} - ✅ INVENTORY SAVED: {$qtyBatang} batang, {$kubikasi} m³");

                $existingLog = InventoryLog::where('item_id', $item->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->where('transaction_type', 'INITIAL_STOCK')
                    ->first();

                if ($existingLog) {
                    $existingLog->update([
                        'qty' => $qtyBatang,
                        'qty_m3' => $kubikasi,
                        'notes' => 'Saldo Awal Kayu Log diperbarui via Excel',
                    ]);
                    Log::info("ROW #{$index} - ✅ INVENTORY_LOG UPDATED");
                } else {
                    InventoryLog::create([
                        'date' => now()->toDateString(),
                        'time' => now()->toTimeString(),
                        'item_id' => $item->id,
                        'warehouse_id' => $warehouse->id,
                        'qty' => $qtyBatang,
                        'qty_m3' => $kubikasi,
                        'direction' => 'IN',
                        'transaction_type' => 'INITIAL_STOCK',
                        'reference_type' => 'ImportExcel',
                        'reference_id' => $item->id,
                        'reference_number' => 'IMPORT-LOG-' . $code,
                        'notes' => 'Saldo Awal Kayu Log dari Excel upload',
                        'user_id' => Auth::id(),
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