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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ProdukJadiStockImport implements ToCollection, WithHeadingRow
{
    private $defaultCategory;
    private $defaultUnit;

    public function __construct()
    {
        $this->defaultCategory = Category::firstOrCreate(
            ['name' => 'Produk Jadi'],
            ['description' => 'Produk Jadi Furniture']
        );

        $this->defaultUnit = Unit::firstOrCreate(
            ['short_name' => 'PCS'],
            [
                'name' => 'PCS',
                'description' => 'Pieces',
            ]
        );
    }

    public function collection(Collection $rows)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $skippedRows = [];
        $processedRows = 0;

        Log::info('========== MULAI IMPORT PRODUK JADI ==========');
        Log::info('Total rows dari Excel: ' . $rows->count());

        foreach ($rows as $index => $row) {
            $rowData = $row->toArray();
            Log::info("ROW #{$index} - RAW DATA:", $rowData);

            $normalized = [];
            foreach ($rowData as $key => $value) {
                $normalized[strtolower(trim($key))] = $value;
            }

            $namaProduk = trim($normalized['nama_produk'] ?? $normalized['nama produk'] ?? '');
            $kodeBarang = trim($normalized['kode_barang'] ?? $normalized['kode barang'] ?? '');
            $stokAwal = isset($normalized['stok_awal'])
                ? (float) $normalized['stok_awal']
                : (isset($normalized['stok awal']) ? (float) $normalized['stok awal'] : 0);
            $gudangRaw = trim($normalized['gudang'] ?? '');
            $hsCodeRaw = $normalized['hs_code'] ?? $normalized['hs code'] ?? null;

            if (empty($namaProduk) || $stokAwal <= 0 || empty($gudangRaw)) {
                Log::warning("ROW #{$index} DITOLAK (kolom wajib kosong / stok <= 0)");
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason' => 'Kolom nama_produk, stok_awal, atau gudang kosong / stok <= 0',
                    'data' => $normalized,
                ];
                continue;
            }

            $hsCode = null;
            if (!empty($hsCodeRaw)) {
                $hsCode = $this->sanitizeHsCode((string) $hsCodeRaw);
            } else {
                Log::warning("ROW #{$index} - HS Code kosong, item tetap dibuat tanpa HS Code.");
            }

            $kategoriName = trim($normalized['kategori'] ?? '');
            $satuanName = trim($normalized['satuan'] ?? '');

            $category = $kategoriName
                ? Category::firstOrCreate(
                    ['name' => $kategoriName],
                    ['description' => 'Kategori dari import Produk Jadi']
                )
                : $this->defaultCategory;

            if ($satuanName) {
                $short = strtoupper($satuanName);
                $unit = Unit::firstOrCreate(
                    ['short_name' => $short],
                    [
                        'name' => $satuanName,
                        'description' => 'Satuan dari import Produk Jadi',
                    ]
                );
            } else {
                $unit = $this->defaultUnit;
            }

            $gudangCode = strtoupper($gudangRaw);

            try {
                Log::info("ROW #{$index} - CREATING/UPDATING ITEM: {$namaProduk}");

                $nwPerBox = $normalized['nw_per_box'] ?? $normalized['nw per box'] ?? null;
                $gwPerBox = $normalized['gw_per_box'] ?? $normalized['gw per box'] ?? null;
                $woodPerPcs = $normalized['wood_consumed_per_pcs'] ?? $normalized['wood consumed per pcs'] ?? null;
                $m3Carton = $normalized['m3_per_carton'] ?? $normalized['m3 per carton'] ?? null;

                // 1) Master item
                $item = Item::updateOrCreate(
                    ['code' => $kodeBarang],
                    [
                        'name' => $namaProduk,
                        'category_id' => $category->id,
                        'unit_id' => $unit->id,
                        'hs_code' => $hsCode,
                        'nw_per_box' => $nwPerBox !== null ? (float) $nwPerBox : null,
                        'gw_per_box' => $gwPerBox !== null ? (float) $gwPerBox : null,
                        'wood_consumed_per_pcs' => $woodPerPcs !== null ? (float) $woodPerPcs : null,
                        'm3_per_carton' => $m3Carton !== null ? (float) $m3Carton : null,
                    ]
                );

                Log::info("ROW #{$index} - ITEM SAVED: ID {$item->id}");

                // 2) Stock movement saldo awal (legacy)
                if ($stokAwal > 0) {
                    $existingMovement = StockMovement::where('item_id', $item->id)
                        ->where('notes', 'LIKE', '%Saldo Awal Produk Jadi%')
                        ->first();

                    if ($existingMovement) {
                        $existingMovement->update([
                            'quantity' => $stokAwal,
                            'notes' => 'Saldo Awal Produk Jadi diperbarui via Excel upload.',
                        ]);
                        Log::info("ROW #{$index} - MOVEMENT UPDATED");
                    } else {
                        StockMovement::create([
                            'item_id' => $item->id,
                            'type' => 'Stok Masuk',
                            'quantity' => $stokAwal,
                            'notes' => 'Saldo Awal Produk Jadi dari Excel upload.',
                        ]);
                        Log::info("ROW #{$index} - MOVEMENT CREATED");
                    }
                }

                // 3) Inventory per gudang
                $warehouse = Warehouse::whereRaw('UPPER(code) = ?', [$gudangCode])->first();

                if (!$warehouse) {
                    $skippedRows[] = [
                        'row_number' => $index + 2,
                        'item_name' => $namaProduk,
                        'reason' => 'Kode gudang tidak ditemukan: ' . $gudangRaw,
                    ];
                    Log::warning("ROW #{$index} - GUDANG TIDAK DITEMUKAN: {$gudangRaw}");
                } else {
                    // Hitung qty_m3 kalau ada m3_per_carton
                    $qtyM3 = 0;
                    if ($m3Carton !== null && $m3Carton > 0) {
                        $qtyM3 = (float) $m3Carton * $stokAwal;
                    }

                    // Cek stok lama untuk inventory_logs
                    $oldInventory = Inventory::where('item_id', $item->id)
                        ->where('warehouse_id', $warehouse->id)
                        ->first();
                    $oldQty = $oldInventory ? (float) $oldInventory->qty : 0;

                    // Update inventory
                    Inventory::updateOrCreate(
                        [
                            'item_id' => $item->id,
                            'warehouse_id' => $warehouse->id,
                        ],
                        [
                            'qty_pcs' => $stokAwal,
                            'qty_m3' => $qtyM3,
                        ]
                    );

                    Log::info("ROW #{$index} - ✅ INVENTORY DISIMPAN: {$stokAwal} pcs (Warehouse ID: {$warehouse->id}, qty_m3: {$qtyM3})");

                    // ✅ 4) Catat ke inventory_logs
                    $existingLog = InventoryLog::where('item_id', $item->id)
                        ->where('warehouse_id', $warehouse->id)
                        ->where('transaction_type', 'INITIAL_STOCK')
                        ->first();

                    if ($existingLog) {
                        // Update log yang sudah ada (jika re-import)
                        $existingLog->update([
                            'qty' => $stokAwal,
                            'qty_m3' => $qtyM3,
                            'notes' => 'Saldo Awal Produk Jadi diperbarui via Excel',
                        ]);
                        Log::info("ROW #{$index} - ✅ INVENTORY_LOG UPDATED");
                    } else {
                        // Buat log baru
                        InventoryLog::create([
                            'date' => now()->toDateString(),
                            'time' => now()->toTimeString(),
                            'item_id' => $item->id,
                            'warehouse_id' => $warehouse->id,
                            'qty' => $stokAwal,
                            'qty_m3' => $qtyM3,
                            'direction' => 'IN',
                            'transaction_type' => 'INITIAL_STOCK',
                            'reference_type' => 'ImportExcel',
                            'reference_id' => $item->id,
                            'reference_number' => 'IMPORT-PJ-' . $kodeBarang,
                            'notes' => 'Saldo Awal Produk Jadi dari Excel upload',
                            'user_id' => Auth::id(),
                        ]);
                        Log::info("ROW #{$index} - ✅ INVENTORY_LOG CREATED");
                    }
                }

                $processedRows++;
                Log::info("ROW #{$index} - SUCCESS!");
            } catch (\Exception $e) {
                Log::error("ROW #{$index} - ERROR: " . $e->getMessage());

                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'item_name' => $namaProduk,
                    'reason' => 'Error: ' . $e->getMessage(),
                ];
            }
        }

        if (!empty($skippedRows)) {
            Log::warning('Baris Produk Jadi yang ditolak:', $skippedRows);
        }

        Log::info('========== IMPORT PRODUK JADI SELESAI ==========');
        Log::info("Berhasil: {$processedRows}, Ditolak: " . count($skippedRows));
    }

    private function sanitizeHsCode(string $hsCode): string
    {
        $clean = preg_replace('/[^0-9.]/', '', (string) $hsCode);

        if (strpos($clean, '.') === false && strlen($clean) >= 6) {
            $clean = substr($clean, 0, 4) . '.' . substr($clean, 4);
        }

        return $clean;
    }
}
