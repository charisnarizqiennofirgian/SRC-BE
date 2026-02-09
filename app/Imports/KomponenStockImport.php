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
                $kode = trim((string) ($row['kode'] ?? ''));
                $nama = trim((string) ($row['nama'] ?? ''));
                $kategori = trim((string) ($row['kategori'] ?? ''));
                $satuan = trim((string) ($row['satuan'] ?? ''));
                $gudang = trim((string) ($row['gudang'] ?? ''));

                if ($kode === '' || $nama === '') {
                    continue;
                }

                // Category
                $category = Category::firstOrCreate(
                    ['name' => $kategori],
                    [
                        'description' => 'Kategori untuk Komponen',
                        'created_by' => 1,
                    ]
                );

                // Unit
                $unit = Unit::firstOrCreate(
                    ['name' => $satuan],
                    [
                        'short_name' => $satuan,
                    ]
                );

                // Warehouse
                $warehouse = Warehouse::where('code', $gudang)
                    ->orWhere('name', $gudang)
                    ->first();

                if (!$warehouse) {
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

                $totalM3 = $m3PerPcs * $stokAwal;

                $specifications = [
                    'p' => $p,
                    'l' => $l,
                    't' => $t,
                    'm3_per_pcs' => $m3PerPcs,
                ];

                // 1. Master Item
                $item = Item::firstOrCreate(
                    ['code' => $kode],
                    [
                        'name' => $nama,
                        'category_id' => $category->id,
                        'unit_id' => $unit->id,
                        'uuid' => Str::uuid(),
                        'type' => 'component',
                    ]
                );

                $item->specifications = $specifications;
                $item->category_id = $category->id;
                $item->unit_id = $unit->id;
                $item->save();

                // 2. Update Inventory
                if ($stokAwal > 0) {
                    // Cek inventory lama
                    $oldInventory = Inventory::where('item_id', $item->id)
                        ->where('warehouse_id', $warehouse->id)
                        ->first();
                    $oldQty = $oldInventory ? (float) $oldInventory->qty : 0;

                    // Update atau create inventory
                    Inventory::updateOrCreate(
                        [
                            'item_id' => $item->id,
                            'warehouse_id' => $warehouse->id,
                        ],
                        [
                            'qty_pcs' => $stokAwal,
                            'qty_m3' => $totalM3,
                        ]
                    );

                    Log::info("✅ Import Komponen: {$nama} - Stok {$stokAwal} pcs di {$warehouse->name}");

                    // ✅ 3. Catat ke inventory_logs
                    $existingLog = InventoryLog::where('item_id', $item->id)
                        ->where('warehouse_id', $warehouse->id)
                        ->where('transaction_type', 'INITIAL_STOCK')
                        ->first();

                    if ($existingLog) {
                        $existingLog->update([
                            'qty' => $stokAwal,
                            'qty_m3' => $totalM3,
                            'notes' => 'Saldo Awal Komponen diperbarui via Excel',
                        ]);
                        Log::info("ROW #{$index} - ✅ INVENTORY_LOG UPDATED");
                    } else {
                        InventoryLog::create([
                            'date' => now()->toDateString(),
                            'time' => now()->toTimeString(),
                            'item_id' => $item->id,
                            'warehouse_id' => $warehouse->id,
                            'qty' => $stokAwal,
                            'qty_m3' => $totalM3,
                            'direction' => 'IN',
                            'transaction_type' => 'INITIAL_STOCK',
                            'reference_type' => 'ImportExcel',
                            'reference_id' => $item->id,
                            'reference_number' => 'IMPORT-KOMP-' . $kode,
                            'notes' => 'Saldo Awal Komponen dari Excel upload',
                            'user_id' => Auth::id(),
                        ]);
                        Log::info("ROW #{$index} - ✅ INVENTORY_LOG CREATED");
                    }
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
            'kode' => 'required|string|max:255',
            'nama' => 'required|string|max:255',
            'kategori' => 'required|string|max:255',
            'satuan' => 'required|string|max:255',
            'gudang' => 'required|string|max:255',
            'p' => 'required|numeric|min:0',
            'l' => 'required|numeric|min:0',
            't' => 'required|numeric|min:0',
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
