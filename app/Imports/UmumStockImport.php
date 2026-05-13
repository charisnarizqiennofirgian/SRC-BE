<?php

namespace App\Imports;

use App\Models\Item;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\Inventory;
use App\Models\InventoryLog;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\Importable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class UmumStockImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{
    use Importable;

    private $defaultWarehouseId;

    public function __construct()
    {
        // Default gudang untuk barang umum = Gudang Packing atau bisa disesuaikan
        $this->defaultWarehouseId = Warehouse::where('code', 'PACKING')->value('id') ?? 11;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            try {
                $kode = strtoupper(trim((string) ($row['kode'] ?? '')));
                $nama = trim((string) ($row['nama'] ?? ''));

                $kategori = strtoupper(trim((string) ($row['kategori'] ?? '')));
                $satuan   = strtoupper(trim((string) ($row['satuan']   ?? '')));

                if ($kode === '' || $nama === '' || $kategori === '' || $satuan === '') {
                    Log::warning("ROW #{$index} - DITOLAK: kode/nama/kategori/satuan kosong");
                    continue;
                }

                // Category
                $category = Category::withTrashed()->where('name', $kategori)->first();
                if (!$category) {
                    try {
                        $category = Category::create([
                            'name' => $kategori,
                            'type' => 'umum',
                            'description' => 'Kategori untuk barang umum',
                            'created_by' => 1,
                        ]);
                    } catch (\Exception $e) {
                        $category = Category::withTrashed()->where('name', $kategori)->first();
                        if (!$category) throw $e;
                    }
                }
                if ($category && $category->trashed()) {
                    $category->restore();
                }

                // Unit
                $unit = Unit::withTrashed()->where('name', $satuan)->first();
                if (!$unit) {
                    try {
                        $unit = Unit::create([
                            'name' => $satuan,
                            'short_name' => $satuan,
                            'symbol' => $satuan,
                            'description' => 'Satuan untuk ' . $satuan,
                        ]);
                    } catch (\Exception $e) {
                        $unit = Unit::withTrashed()->where('name', $satuan)->first();
                        if (!$unit) throw $e;
                    }
                }
                if ($unit && $unit->trashed()) {
                    $unit->restore();
                }

                // Gudang dari Excel atau default
                $gudangCode = strtoupper(trim($row['gudang'] ?? ''));
                $warehouseId = $this->defaultWarehouseId;

                if ($gudangCode) {
                    $warehouse = Warehouse::whereRaw('UPPER(code) = ?', [$gudangCode])->first();
                    if ($warehouse) {
                        $warehouseId = $warehouse->id;
                    }
                }

                $stokAwal = (float) ($row['stok_awal'] ?? 0);

                // 1. Master Item
                $item = Item::withTrashed()->where('code', $kode)->first();
                if (!$item) {
                    try {
                        $item = Item::create([
                            'code' => $kode,
                            'name' => $nama,
                            'category_id' => $category->id,
                            'unit_id' => $unit->id,
                            'type' => 'um',
                            'uuid' => Str::uuid(),
                        ]);
                    } catch (\Exception $e) {
                        $item = Item::withTrashed()->where('code', $kode)->first();
                        if (!$item) throw $e;
                    }
                }
                if ($item && $item->trashed()) {
                    $item->restore();
                }

                $item->stock = $stokAwal;
                $item->category_id = $category->id;
                $item->unit_id = $unit->id;
                $item->save();

                Log::info("ROW #{$index} - ITEM SAVED: {$nama} (ID: {$item->id})");

                // 2. Update Inventory (jika ada stok)
                if ($stokAwal > 0 && $warehouseId) {
                    Inventory::updateOrCreate(
                        [
                            'item_id' => $item->id,
                            'warehouse_id' => $warehouseId,
                        ],
                        [
                            'qty_pcs' => $stokAwal,
                        ]
                    );

                    Log::info("ROW #{$index} - ✅ INVENTORY SAVED: {$stokAwal} (Warehouse ID: {$warehouseId})");

                    // ✅ 3. Catat ke inventory_logs
                    $existingLog = InventoryLog::where('item_id', $item->id)
                        ->where('warehouse_id', $warehouseId)
                        ->where('transaction_type', 'INITIAL_STOCK')
                        ->first();

                    if ($existingLog) {
                        $existingLog->update([
                            'qty' => $stokAwal,
                            'notes' => 'Saldo Awal Barang Umum diperbarui via Excel',
                        ]);
                        Log::info("ROW #{$index} - ✅ INVENTORY_LOG UPDATED");
                    } else {
                        InventoryLog::create([
                            'date' => now()->toDateString(),
                            'time' => now()->toTimeString(),
                            'item_id' => $item->id,
                            'warehouse_id' => $warehouseId,
                            'qty' => $stokAwal,
                            'direction' => 'IN',
                            'transaction_type' => 'INITIAL_STOCK',
                            'reference_type' => 'ImportExcel',
                            'reference_id' => $item->id,
                            'reference_number' => 'IMPORT-UMUM-' . $kode,
                            'notes' => 'Saldo Awal Barang Umum dari Excel upload',
                            'user_id' => Auth::id(),
                        ]);
                        Log::info("ROW #{$index} - ✅ INVENTORY_LOG CREATED");
                    }
                }
            } catch (\Throwable $e) {
                Log::error(
                    'Error import Barang Umum di baris ' . ($index + 2) . ': ' . $e->getMessage(),
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
