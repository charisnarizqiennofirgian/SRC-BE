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

class KomponenStockImport implements
    ToCollection,
    WithHeadingRow,
    WithCustomCsvSettings
{
    use Importable;

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            try {
                // Normalisasi string
                $kode       = trim((string) ($row['kode']          ?? ''));
                $nama       = trim((string) ($row['nama_komponen'] ?? ''));
                $kategori   = trim((string) ($row['kategori']      ?? 'Komponen'));
                $satuan     = trim((string) ($row['satuan']        ?? ''));
                $gudang     = trim((string) ($row['gudang']        ?? ''));
                $buyer      = trim((string) ($row['buyer']         ?? ''));
                $namaProduk = trim((string) ($row['nama_produk']   ?? ''));
                $jenisKayu  = trim((string) ($row['jenis_kayu']    ?? ''));

                if ($kode === '') {
                    continue;
                }

                // Kalau nama kosong, pakai kode sebagai nama
                if ($nama === '') {
                    $nama = $kode;
                }

                // Category
                $category = Category::firstOrCreate(
                    ['name' => $kategori],
                    [
                        'description' => 'Kategori untuk Komponen',
                        'created_by' => 1,
                    ]
                );

                // Unit — cari by name atau short_name karena short_name ada unique constraint
                $unit = Unit::where('name', $satuan)
                    ->orWhere('short_name', $satuan)
                    ->first();
                if (!$unit) {
                    $unit = Unit::create(['name' => $satuan, 'short_name' => $satuan]);
                }

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

                $t        = (float) ($row['t']          ?? 0);
                $l        = (float) ($row['l']          ?? 0);
                $p        = (float) ($row['p']          ?? 0);
                $qtySet   = (float) ($row['qty_set']     ?? 0);
                $qtyNat   = (float) ($row['qty_natural'] ?? 0);
                $qtyWarna = (float) ($row['qty_warna']   ?? 0);
                $m3Total  = (float) ($row['m3_total']   ?? 0);
                $m3Nat    = (float) ($row['m3_natural'] ?? 0);
                $m3Warna  = (float) ($row['m3_warna']   ?? 0);

                // Kalau m3_total diisi dan natural+warna kosong → pakai m3_total untuk natural
                if ($m3Total > 0 && $m3Nat == 0 && $m3Warna == 0) {
                    $m3Nat = $m3Total;
                }
                $stokAwal = $qtyNat + $qtyWarna;

                // m3_per_pcs dihitung otomatis dari dimensi
                $m3PerPcs = ($p > 0 && $l > 0 && $t > 0)
                    ? ($t * $l * $p) / 1_000_000_000
                    : 0;

                $totalM3 = $m3PerPcs * $stokAwal;

                $specifications = [
                    'p'          => $p,
                    'l'          => $l,
                    't'          => $t,
                    'm3_per_pcs' => $m3PerPcs,
                ];

                // 1. Master Item
                $item = Item::updateOrCreate(
                    ['code' => $kode],
                    [
                        'name'           => $nama,
                        'category_id'    => $category->id,
                        'unit_id'        => $unit->id,
                        'specifications' => $specifications,
                        'stock'          => $stokAwal,
                        'qty_set'        => $qtySet,
                        'qty_natural'    => $qtyNat,
                        'qty_warna'      => $qtyWarna,
                        'm3_natural'     => $m3Nat,
                        'm3_warna'       => $m3Warna,
                        'buyer_name'     => $buyer      ?: null,
                        'nama_produk'    => $namaProduk ?: null,
                        'jenis_kayu'     => $jenisKayu  ?: null,
                        'volume_m3'      => $m3PerPcs,
                    ]
                );

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

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
        ];
    }
}
