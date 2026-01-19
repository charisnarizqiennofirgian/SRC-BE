<?php

namespace App\Imports;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\Inventory;
use App\Models\InventoryLog;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

class ProductsImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{
    private $productCategoryId;
    private $units;
    private $packingWarehouseId;

    public function __construct()
    {
        $this->productCategoryId = Category::where('name', 'Produk Jadi')->value('id');

        $this->units = Unit::pluck('id', 'name')->mapWithKeys(function ($id, $name) {
            return [strtolower(trim($name)) => $id];
        });

        // ✅ Default gudang untuk Produk Jadi = Gudang Packing (ID 11)
        $this->packingWarehouseId = Warehouse::where('code', 'PACKING')->value('id') ?? 11;
    }

    public function collection(Collection $rows)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $skippedRows = [];
        $processedRows = 0;

        foreach ($rows as $index => $row)
        {
            // ✅ VALIDASI WAJIB
            if (empty($row['kode']) || empty($row['nama_produk']) || empty($row['satuan'])) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason' => 'Kolom kode, nama_produk, atau satuan kosong'
                ];
                continue;
            }

            $unitName = strtolower(trim($row['satuan']));
            $unitId = $this->units[$unitName] ?? null;

            if (!$unitId) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason' => "Satuan '{$row['satuan']}' tidak ditemukan di master unit"
                ];
                continue;
            }

            if (!$this->productCategoryId) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason' => 'Kategori Produk Jadi tidak ditemukan'
                ];
                continue;
            }

            $stok = isset($row['stok']) ? (float) $row['stok'] : 0;

            try {
                // ✅ 1. CREATE/UPDATE ITEM
                $item = Item::updateOrCreate(
                    ['code' => $row['kode']],
                    [
                        'name' => $row['nama_produk'],
                        'description' => $row['deskripsi'] ?? null,
                        'stock' => $stok,
                        'category_id' => $this->productCategoryId,
                        'unit_id' => $unitId,
                    ]
                );

                // ✅ 2. BIKIN STOCK MOVEMENT (legacy - tetap dipertahankan)
                if ($stok > 0) {
                    $existingMovement = StockMovement::where('item_id', $item->id)
                                                     ->where('notes', 'LIKE', '%Import Excel Produk%')
                                                     ->first();

                    if ($existingMovement) {
                        $existingMovement->update([
                            'quantity' => $stok,
                            'notes' => 'Import Excel Produk (Updated)',
                        ]);
                    } else {
                        StockMovement::create([
                            'item_id' => $item->id,
                            'type' => 'Stok Masuk',
                            'quantity' => $stok,
                            'notes' => 'Import Excel Produk',
                        ]);
                    }

                    // ✅ 3. UPDATE INVENTORY (tabel inventories)
                    $inventory = Inventory::where('item_id', $item->id)
                        ->where('warehouse_id', $this->packingWarehouseId)
                        ->first();

                    $oldQty = $inventory ? (float) $inventory->qty : 0;

                    Inventory::updateOrCreate(
                        [
                            'item_id' => $item->id,
                            'warehouse_id' => $this->packingWarehouseId,
                        ],
                        [
                            'qty' => $stok,
                        ]
                    );

                    // ✅ 4. CATAT KE INVENTORY_LOGS
                    // Cek apakah sudah ada log INITIAL_STOCK untuk item ini
                    $existingLog = InventoryLog::where('item_id', $item->id)
                        ->where('warehouse_id', $this->packingWarehouseId)
                        ->where('transaction_type', 'INITIAL_STOCK')
                        ->first();

                    if ($existingLog) {
                        // Update log yang sudah ada (jika re-import)
                        $existingLog->update([
                            'qty' => $stok,
                            'notes' => 'Import Excel Produk Jadi (Updated)',
                        ]);
                    } else {
                        // Buat log baru
                        InventoryLog::create([
                            'date' => now()->toDateString(),
                            'time' => now()->toTimeString(),
                            'item_id' => $item->id,
                            'warehouse_id' => $this->packingWarehouseId,
                            'qty' => $stok,
                            'direction' => 'IN',
                            'transaction_type' => 'INITIAL_STOCK',
                            'reference_type' => 'ImportExcel',
                            'reference_id' => $item->id,
                            'reference_number' => 'IMPORT-PRODUK-' . $item->code,
                            'notes' => 'Import Excel Produk Jadi',
                            'user_id' => Auth::id(),
                        ]);
                    }
                }

                $processedRows++;
            } catch (\Exception $e) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'item_name' => $row['nama_produk'],
                    'reason' => 'Error sistem: ' . $e->getMessage()
                ];
                Log::error("Error processing row {$index} for product {$row['nama_produk']}: " . $e->getMessage());
            }
        }

        if (!empty($skippedRows)) {
            Log::warning('Baris Excel Product yang ditolak:', $skippedRows);
        }

        Log::info("Import Product selesai. Berhasil: {$processedRows} baris. Ditolak: " . count($skippedRows) . " baris.");
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';'
        ];
    }
}
