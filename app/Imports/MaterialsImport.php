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
use Maatwebsite\Excel\Concerns\WithValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class MaterialsImport implements ToCollection, WithHeadingRow, WithValidation
{
    // Mapping kategori ke default gudang
    private $categoryWarehouseMap = [
        'produk jadi' => 'PACKING',
        'karton box' => 'PACKING',
        'kayu rst' => 'RSTK',        // Gudang KD (RST Kering)
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

                    // ✅ Ambil gudang dari Excel atau default berdasarkan kategori
                    $gudangCode = null;
                    if (!empty($row['gudang_awal'])) {
                        $gudangCode = strtoupper(trim($row['gudang_awal']));
                    } else {
                        // Default gudang berdasarkan kategori
                        $lowerCat = strtolower($category->name);
                        foreach ($this->categoryWarehouseMap as $key => $code) {
                            if (str_contains($lowerCat, $key)) {
                                $gudangCode = $code;
                                break;
                            }
                        }
                    }

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

                    // ✅ BIKIN STOCK MOVEMENT (legacy)
                    if ((float)$stokBaru !== (float)$stokLama) {
                        $selisih = (float)$stokBaru - (float)$stokLama;
                        $movementType = $selisih > 0 ? 'Stok Masuk' : 'Stok Keluar';

                        StockMovement::create([
                            'item_id'  => $item->id,
                            'type'     => $movementType,
                            'quantity' => $selisih,
                            'notes'    => "Import Excel: Stok berubah dari {$stokLama} menjadi {$stokBaru}",
                        ]);
                    }

                    // ✅ UPDATE INVENTORY & INVENTORY_LOGS (jika ada stok dan gudang)
                    if ($stokBaru > 0 && $gudangCode) {
                        $warehouse = Warehouse::whereRaw('UPPER(code) = ?', [$gudangCode])->first();

                        if ($warehouse) {
                            // Cek stok lama di inventory
                            $oldInventory = Inventory::where('item_id', $item->id)
                                ->where('warehouse_id', $warehouse->id)
                                ->first();
                            $oldQty = $oldInventory ? (float) $oldInventory->qty : 0;

                            // Update inventory
                            Inventory::updateOrCreate(
                                [
                                    'item_id'      => $item->id,
                                    'warehouse_id' => $warehouse->id,
                                ],
                                [
                                    'qty' => $stokBaru,
                                ]
                            );

                            Log::info("ROW #{$index} - ✅ INVENTORY DISIMPAN: {$stokBaru} (Warehouse: {$gudangCode})");

                            // ✅ Catat ke inventory_logs
                            $existingLog = InventoryLog::where('item_id', $item->id)
                                ->where('warehouse_id', $warehouse->id)
                                ->where('transaction_type', 'INITIAL_STOCK')
                                ->first();

                            if ($existingLog) {
                                $existingLog->update([
                                    'qty' => $stokBaru,
                                    'notes' => 'Import Excel Material (Updated)',
                                ]);
                                Log::info("ROW #{$index} - ✅ INVENTORY_LOG UPDATED");
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
                                    'reference_number' => 'IMPORT-MAT-' . $row['kode'],
                                    'notes' => 'Import Excel Material - Stok Awal',
                                    'user_id' => Auth::id(),
                                ]);
                                Log::info("ROW #{$index} - ✅ INVENTORY_LOG CREATED");
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

    public function rules(): array
    {
        return [
            'kode' => 'required|string',
            'nama' => 'required',
            'kategori' => 'required|string',
            'satuan' => 'required|string',
            'deskripsi' => 'nullable',
            'stok_awal' => 'nullable|numeric|min:0',
            'gudang_awal' => 'nullable|string',
            'spec_p' => 'nullable|numeric|min:0',
            'spec_l' => 'nullable|numeric|min:0',
            'spec_t' => 'nullable|numeric|min:0',
            'nw_per_box' => 'nullable|numeric|min:0',
            'gw_per_box' => 'nullable|numeric|min:0',
            'wood_consumed_per_pcs' => 'nullable|numeric|min:0',
            'm3_per_carton' => 'nullable|numeric|min:0',
            'hs_code' => 'nullable|string|max:50',
        ];
    }
}
