<?php

namespace App\Imports;

use App\Models\Item;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
// ✅ REMOVED: use Maatwebsite\Excel\Concerns\WithValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

// ✅ REMOVED WithValidation
class MaterialsImport implements ToCollection, WithHeadingRow
{
    private $categoryWarehouseMap = [
        'produk jadi' => 'PACKING',
        'karton box' => 'PACKING',
        'kayu rst' => 'RSTK',
        'kayu log' => 'LOG',
        'komponen' => 'MESIN',
    ];

    public function collection(Collection $rows)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $skippedRows = [];
        $processedRows = 0;

        DB::transaction(function () use ($rows, &$skippedRows, &$processedRows) {
            foreach ($rows as $index => $row) {
                try {
                    // ✅ Validation tetap ada di sini
                    if (empty($row['kode']) || empty($row['nama']) || empty($row['kategori']) || empty($row['satuan'])) {
                        $skippedRows[] = [
                            'row_number' => $index + 2,
                            'reason' => 'Kolom kode, nama, kategori, atau satuan kosong'
                        ];
                        continue;
                    }

                    $category = Category::firstOrCreate(
                        ['name' => trim($row['kategori'])],
                        ['name' => trim($row['kategori'])]
                    );

                    $unitShortName = trim($row['satuan']);
                    $unit = Unit::firstOrCreate(
                        ['short_name' => $unitShortName],
                        [
                            'name' => $unitShortName,
                            'short_name' => $unitShortName
                        ]
                    );

                    // ✅ Cast kode to string (handle numeric from Excel)
                    $kode = (string) trim($row['kode']);
                    $item = Item::firstOrNew(['code' => $kode]);

                    $stokLama = $item->stock ?? 0;
                    $stokBaru = isset($row['stok_awal']) ? (float) $row['stok_awal'] : 0;

                    $gudangCode = null;
                    if (!empty($row['gudang_awal'])) {
                        $gudangCode = strtoupper(trim($row['gudang_awal']));
                    } else {
                        $lowerCat = strtolower($category->name);
                        foreach ($this->categoryWarehouseMap as $key => $code) {
                            if (str_contains($lowerCat, $key)) {
                                $gudangCode = $code;
                                break;
                            }
                        }
                    }

                    $item->name = trim($row['nama']);
                    $item->category_id = $category->id;
                    $item->unit_id = $unit->id;
                    $item->description = isset($row['deskripsi']) ? trim($row['deskripsi']) : null;
                    $item->stock = $stokBaru;

                    $lowerCategoryName = strtolower($category->name);

                    if (str_contains($lowerCategoryName, 'kayu log')) {
                        Log::info("ROW #{$index} - 🪵 KATEGORI: Kayu Log DETECTED");
                        Log::info("ROW #{$index} - 📋 DATA EXCEL RAW:", [
                            'jenis_kayu' => $row['jenis_kayu'] ?? 'TIDAK ADA KEY',
                            'tpk' => $row['tpk'] ?? 'TIDAK ADA KEY',
                            'diameter' => $row['diameter'] ?? 'TIDAK ADA KEY',
                            'panjang' => $row['panjang'] ?? 'TIDAK ADA KEY',
                            'kubikasi' => $row['kubikasi'] ?? 'TIDAK ADA KEY',
                        ]);

                        $item->specifications = null;
                        $item->nw_per_box = null;
                        $item->gw_per_box = null;
                        $item->wood_consumed_per_pcs = null;
                        $item->m3_per_carton = null;
                        $item->hs_code = null;

                        $item->jenis_kayu = isset($row['jenis_kayu']) ? trim($row['jenis_kayu']) : null;
                        $item->tpk = isset($row['tpk']) ? trim($row['tpk']) : null;
                        $item->diameter = isset($row['diameter']) ? (float) $row['diameter'] : null;
                        $item->panjang = isset($row['panjang']) ? (float) $row['panjang'] : null;
                        $item->kubikasi = isset($row['kubikasi']) ? (float) $row['kubikasi'] : null;

                        Log::info("ROW #{$index} - 💾 AKAN DISAVE:", [
                            'jenis_kayu' => $item->jenis_kayu,
                            'tpk' => $item->tpk,
                            'diameter' => $item->diameter,
                            'panjang' => $item->panjang,
                            'kubikasi' => $item->kubikasi,
                        ]);
                    } elseif (str_contains($lowerCategoryName, 'karton box') || str_contains($lowerCategoryName, 'kayu rst')) {
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

                        $item->jenis_kayu = null;
                        $item->tpk = null;
                        $item->diameter = null;
                        $item->panjang = null;
                        $item->kubikasi = null;
                    } elseif (str_contains($lowerCategoryName, 'produk jadi')) {
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

                        $item->jenis_kayu = null;
                        $item->tpk = null;
                        $item->diameter = null;
                        $item->panjang = null;
                        $item->kubikasi = null;
                    } else {
                        $item->specifications = null;
                        $item->nw_per_box = null;
                        $item->gw_per_box = null;
                        $item->wood_consumed_per_pcs = null;
                        $item->m3_per_carton = null;
                        $item->hs_code = null;

                        $item->jenis_kayu = null;
                        $item->tpk = null;
                        $item->diameter = null;
                        $item->panjang = null;
                        $item->kubikasi = null;
                    }

                    $item->save();

                    if ((float) $stokBaru !== (float) $stokLama) {
                        $selisih = (float) $stokBaru - (float) $stokLama;
                        $movementType = $selisih > 0 ? 'Stok Masuk' : 'Stok Keluar';

                        StockMovement::create([
                            'item_id' => $item->id,
                            'type' => $movementType,
                            'quantity' => $selisih,
                            'notes' => "Import Excel: Stok berubah dari {$stokLama} menjadi {$stokBaru}",
                        ]);
                    }

                    if ($stokBaru > 0 && $gudangCode) {
                        $warehouse = Warehouse::whereRaw('UPPER(code) = ?', [$gudangCode])->first();

                        if ($warehouse) {
                            $oldInventory = Inventory::where('item_id', $item->id)
                                ->where('warehouse_id', $warehouse->id)
                                ->first();
                            $oldQty = $oldInventory ? (float) $oldInventory->qty : 0;

                            Inventory::updateOrCreate(
                                [
                                    'item_id' => $item->id,
                                    'warehouse_id' => $warehouse->id,
                                ],
                                [
                                    'qty' => $stokBaru,
                                ]
                            );

                            Log::info("ROW #{$index} - INVENTORY DISIMPAN: {$stokBaru} (Warehouse: {$gudangCode})");

                            $existingLog = InventoryLog::where('item_id', $item->id)
                                ->where('warehouse_id', $warehouse->id)
                                ->where('transaction_type', 'INITIAL_STOCK')
                                ->first();

                            if ($existingLog) {
                                $existingLog->update([
                                    'qty' => $stokBaru,
                                    'notes' => 'Import Excel Material (Updated)',
                                ]);
                                Log::info("ROW #{$index} - INVENTORY_LOG UPDATED");
                            } else {
                                InventoryLog::create([
                                    'date' => now()->toDateString(),
                                    'time' => now()->toTimeString(),
                                    'item_id' => $item->id,
                                    'warehouse_id' => $warehouse->id,
                                    'qty' => $stokBaru,
                                    'direction' => 'IN',
                                    'transaction_type' => 'INITIAL_STOCK',
                                    'reference_type' => 'ImportExcel',
                                    'reference_id' => $item->id,
                                    'reference_number' => 'IMPORT-MAT-' . trim($row['kode']),
                                    'notes' => 'Import Excel Material - Stok Awal',
                                    'user_id' => Auth::id(),
                                ]);
                                Log::info("ROW #{$index} - INVENTORY_LOG CREATED");
                            }
                        } else {
                            Log::warning("ROW #{$index} - Gudang tidak ditemukan: {$gudangCode}");
                        }
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

    // ✅ REMOVED rules() method completely
}