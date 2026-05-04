<?php

namespace App\Imports;

use App\Models\Item;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\StockMovement;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\Stock;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

class JeblosanStockImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{
    private $categoryJeblosan;
    private $defaultUnit;

    public function __construct()
    {
        $this->categoryJeblosan = Category::firstOrCreate(
            ['name' => 'Jeblosan'],
            ['description' => 'Bahan Baku Jeblosan']
        );

        $this->defaultUnit = Unit::firstOrCreate(
            ['name' => 'Pieces'],
            ['short_name' => 'PCS']
        );
    }

    public function collection(Collection $rows)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $skippedRows   = [];
        $processedRows = 0;

        Log::info('=== MULAI IMPORT JEBLOSAN ===');
        Log::info('Total rows: ' . $rows->count());

        foreach ($rows as $index => $row) {
            // Wajib: nama_dasar, tebal_mm, stok_awal, gudang
            if (
                empty($row['nama_dasar']) ||
                !isset($row['tebal_mm']) || $row['tebal_mm'] === '' ||
                !isset($row['stok_awal']) || $row['stok_awal'] === '' ||
                !isset($row['gudang'])    || trim($row['gudang']) === ''
            ) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason'     => 'Kolom nama_dasar, tebal_mm, stok_awal, atau gudang kosong',
                ];
                continue;
            }

            $namaDasar   = trim($row['nama_dasar']);
            $kodeBarang  = trim($row['kode_barang'] ?? '');
            $satuanName  = trim($row['satuan'] ?? '');

            $unit = $satuanName
                ? Unit::firstOrCreate(
                    ['name' => $satuanName],
                    ['short_name' => strtoupper(substr($satuanName, 0, 5))]
                )
                : $this->defaultUnit;

            $gudangCode = strtoupper(trim($row['gudang']));

            $t = (float) $row['tebal_mm'];
            $l = (float) ($row['lebar_mm']  ?? 0);
            $p = (float) ($row['panjang_mm'] ?? 0);

            $stokAwal = (float) $row['stok_awal'];

            // Nama unik berdasarkan dimensi
            $uniqueName = "{$namaDasar} {$t}x{$l}x{$p}";

            // Kubikasi per pcs
            // Kalau ada m3_per_pcs di Excel, pakai itu. Kalau tidak, hitung dari dimensi
            $m3Input        = isset($row['m3_per_pcs']) && $row['m3_per_pcs'] > 0
                ? (float) $row['m3_per_pcs']
                : ($t * $l * $p) / 1_000_000_000;

            $kubikasiPerPcs = $m3Input;
            $totalM3        = $kubikasiPerPcs * $stokAwal;

            $specifications = [
                't'          => $t,
                'l'          => $l,
                'p'          => $p,
                'm3_per_pcs' => $kubikasiPerPcs,
            ];

            try {
                Log::info("ROW #{$index} - ITEM: {$uniqueName}");

                // 1. Master Item
                $item = Item::updateOrCreate(
                    ['code' => $kodeBarang],
                    [
                        'name'           => $uniqueName,
                        'category_id'    => $this->categoryJeblosan->id,
                        'unit_id'        => $unit->id,
                        'specifications' => $specifications,
                        'stock'          => $stokAwal,
                        'volume_m3'      => $kubikasiPerPcs,
                    ]
                );

                // 2. Stock Movement (legacy)
                if ($stokAwal > 0) {
                    $existing = StockMovement::where('item_id', $item->id)
                        ->where('notes', 'LIKE', '%Saldo Awal (Jeblosan)%')
                        ->first();

                    if ($existing) {
                        $existing->update([
                            'quantity' => $stokAwal,
                            'notes'    => 'Saldo Awal (Jeblosan) diperbarui via Excel upload.',
                        ]);
                    } else {
                        StockMovement::create([
                            'item_id'  => $item->id,
                            'type'     => 'Stok Masuk',
                            'quantity' => $stokAwal,
                            'notes'    => 'Saldo Awal (Jeblosan) dari Excel upload.',
                        ]);
                    }
                }

                // 3. Stok per Gudang
                $warehouse = Warehouse::whereRaw('UPPER(code) = ?', [$gudangCode])->first();

                if (!$warehouse) {
                    $skippedRows[] = [
                        'row_number' => $index + 2,
                        'item_name'  => $uniqueName,
                        'reason'     => 'Kode gudang tidak ditemukan: ' . $row['gudang'],
                    ];
                    Log::warning("ROW #{$index} - GUDANG TIDAK DITEMUKAN: {$row['gudang']}");
                } else {
                    // Update stocks (legacy)
                    Stock::updateOrCreate(
                        ['item_id' => $item->id, 'warehouse_id' => $warehouse->id],
                        ['quantity' => $stokAwal]
                    );

                    // Update inventories
                    Inventory::updateOrCreate(
                        [
                            'item_id'        => $item->id,
                            'warehouse_id'   => $warehouse->id,
                            'ref_po_id'      => null,
                            'ref_product_id' => null,
                        ],
                        [
                            'qty_pcs' => $stokAwal,
                            'qty_m3'  => $totalM3,
                        ]
                    );

                    // Catat inventory_log
                    $existingLog = InventoryLog::where('item_id', $item->id)
                        ->where('warehouse_id', $warehouse->id)
                        ->where('transaction_type', 'INITIAL_STOCK')
                        ->first();

                    if ($existingLog) {
                        $existingLog->update([
                            'qty'   => $stokAwal,
                            'qty_m3' => $totalM3,
                            'notes' => 'Saldo Awal Jeblosan diperbarui via Excel',
                        ]);
                    } else {
                        InventoryLog::create([
                            'date'             => now()->toDateString(),
                            'time'             => now()->toTimeString(),
                            'item_id'          => $item->id,
                            'warehouse_id'     => $warehouse->id,
                            'qty'              => $stokAwal,
                            'qty_m3'           => $totalM3,
                            'direction'        => 'IN',
                            'transaction_type' => 'INITIAL_STOCK',
                            'reference_type'   => 'ImportExcel',
                            'reference_id'     => $item->id,
                            'reference_number' => 'IMPORT-JBL-' . $kodeBarang,
                            'notes'            => 'Saldo Awal Jeblosan dari Excel upload',
                            'user_id'          => Auth::id(),
                        ]);
                    }

                    Log::info("ROW #{$index} - OK: Qty {$stokAwal}, M3 {$totalM3}");
                }

                $processedRows++;
            } catch (\Exception $e) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'item_name'  => $uniqueName,
                    'reason'     => 'Error: ' . $e->getMessage(),
                ];
                Log::error("Error row {$index} jeblosan {$uniqueName}: " . $e->getMessage());
            }
        }

        Log::info("Import Jeblosan selesai. Berhasil: {$processedRows}, Ditolak: " . count($skippedRows));
    }

    public function getCsvSettings(): array
    {
        return ['delimiter' => ';'];
    }
}