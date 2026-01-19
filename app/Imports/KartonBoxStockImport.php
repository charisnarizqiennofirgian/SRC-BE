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
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\Importable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class KartonBoxStockImport implements ToCollection, WithHeadingRow, WithValidation, WithCustomCsvSettings
{
    use Importable;

    private $defaultWarehouseId;

    public function __construct()
    {
        // Default gudang untuk Karton Box = Gudang Packing (ID 11)
        $this->defaultWarehouseId = Warehouse::where('code', 'PACKING')->value('id') ?? 11;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            try {
                $kode = trim((string) ($row['kode'] ?? ''));
                $nama = trim((string) ($row['nama'] ?? ''));

                if ($kode === '' || $nama === '') {
                    Log::warning("ROW #{$index} - DITOLAK: kode atau nama kosong");
                    continue;
                }

                // Category
                $category = Category::firstOrCreate(
                    ['name' => $row['kategori']],
                    [
                        'type'        => 'karton',
                        'description' => 'Kategori untuk Karton Box',
                        'created_by'  => 1,
                    ]
                );

                // Unit
                $unit = Unit::firstOrCreate(
                    ['name' => $row['satuan']],
                    [
                        'short_name'  => $row['satuan'],
                        'symbol'      => $row['satuan'],
                        'description' => 'Satuan untuk ' . $row['satuan'],
                    ]
                );

                // Gudang dari Excel atau default
                $gudangCode = strtoupper(trim($row['gudang'] ?? ''));
                $warehouseId = $this->defaultWarehouseId;

                if ($gudangCode) {
                    $warehouse = Warehouse::whereRaw('UPPER(code) = ?', [$gudangCode])->first();
                    if ($warehouse) {
                        $warehouseId = $warehouse->id;
                    }
                }

                $p = (float) ($row['p'] ?? 0);
                $l = (float) ($row['l'] ?? 0);
                $t = (float) ($row['t'] ?? 0);
                $stokAwal = (float) ($row['stok_awal'] ?? 0);

                $m3PerPcs = 0;
                if ($p > 0 && $l > 0 && $t > 0) {
                    $m3PerPcs = ($p * $l * $t) / 1_000_000_000;
                }

                $totalM3 = $m3PerPcs * $stokAwal;

                $specifications = [
                    'p'          => $p,
                    'l'          => $l,
                    't'          => $t,
                    'm3_per_pcs' => $m3PerPcs,
                ];

                // 1. Master Item
                $item = Item::firstOrCreate(
                    ['code' => $kode],
                    [
                        'name'        => $nama,
                        'category_id' => $category->id,
                        'unit_id'     => $unit->id,
                        'uuid'        => Str::uuid(),
                    ]
                );

                $item->specifications = $specifications;
                $item->stock = $stokAwal;
                $item->category_id = $category->id;
                $item->unit_id = $unit->id;
                $item->save();

                Log::info("ROW #{$index} - ITEM SAVED: {$nama} (ID: {$item->id})");

                // 2. Update Inventory
                if ($stokAwal > 0 && $warehouseId) {
                    Inventory::updateOrCreate(
                        [
                            'item_id'      => $item->id,
                            'warehouse_id' => $warehouseId,
                        ],
                        [
                            'qty'    => $stokAwal,
                            'qty_m3' => $totalM3,
                        ]
                    );

                    Log::info("ROW #{$index} - ✅ INVENTORY SAVED: {$stokAwal} pcs (Warehouse ID: {$warehouseId})");

                    // ✅ 3. Catat ke inventory_logs
                    $existingLog = InventoryLog::where('item_id', $item->id)
                        ->where('warehouse_id', $warehouseId)
                        ->where('transaction_type', 'INITIAL_STOCK')
                        ->first();

                    if ($existingLog) {
                        $existingLog->update([
                            'qty'    => $stokAwal,
                            'qty_m3' => $totalM3,
                            'notes'  => 'Saldo Awal Karton Box diperbarui via Excel',
                        ]);
                        Log::info("ROW #{$index} - ✅ INVENTORY_LOG UPDATED");
                    } else {
                        InventoryLog::create([
                            'date'             => now()->toDateString(),
                            'time'             => now()->toTimeString(),
                            'item_id'          => $item->id,
                            'warehouse_id'     => $warehouseId,
                            'qty'              => $stokAwal,
                            'qty_m3'           => $totalM3,
                            'direction'        => 'IN',
                            'transaction_type' => 'INITIAL_STOCK',
                            'reference_type'   => 'ImportExcel',
                            'reference_id'     => $item->id,
                            'reference_number' => 'IMPORT-BOX-' . $kode,
                            'notes'            => 'Saldo Awal Karton Box dari Excel upload',
                            'user_id'          => Auth::id(),
                        ]);
                        Log::info("ROW #{$index} - ✅ INVENTORY_LOG CREATED");
                    }
                }
            } catch (\Throwable $e) {
                Log::error(
                    'Error import Karton Box di baris ' . ($index + 2) . ': ' . $e->getMessage(),
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
            'gudang'    => 'nullable|string|max:255',
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
