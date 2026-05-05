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
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

class KayuStockImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings, WithCalculatedFormulas
{
    private $categoryKayu;
    private $defaultUnit;

    public function __construct()
    {
        $this->categoryKayu = Category::firstOrCreate(
            ['name' => 'Kayu RST'],
            ['description' => 'Bahan Baku Kayu RST']
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

        $skippedRows = [];
        $processedRows = 0;

        Log::info('=== MULAI IMPORT KAYU RST ===');
        Log::info('Total rows: ' . $rows->count());

        foreach ($rows as $index => $row) {
            // wajib: nama_dasar, tebal_mm, stok_awal, gudang
            if (
                empty($row['nama_dasar']) ||
                !isset($row['tebal_mm']) || $row['tebal_mm'] === '' ||
                !isset($row['stok_awal']) || $row['stok_awal'] === '' ||
                !isset($row['gudang'])    || trim($row['gudang']) === ''
            ) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason' => 'Kolom nama_dasar, tebal_mm, stok_awal, atau gudang kosong',
                ];
                continue;
            }

            $namaDasar = trim($row['nama_dasar']);
            $kodeBarang = trim($row['kode_barang'] ?? '');

            $satuanName = trim($row['satuan'] ?? '');
            $unit = $satuanName
                ? Unit::firstOrCreate(
                    ['name' => $satuanName],
                    ['short_name' => strtoupper(substr($satuanName, 0, 5))]
                )
                : $this->defaultUnit;

            $gudangCodeRaw = trim($row['gudang']);
            $gudangCode = strtoupper($gudangCodeRaw);

            $jenis = isset($row['jenis']) ? trim($row['jenis']) : null;
            $kualitas = isset($row['kualitas']) ? trim($row['kualitas']) : null;
            $bentuk = isset($row['bentuk']) ? trim($row['bentuk']) : null;

            $t = (float) $row['tebal_mm'];
            $l = (float) ($row['lebar_mm'] ?? 0);
            $p = (float) ($row['panjang_mm'] ?? 0);
            $rawCT = $row['cutting_tebal_mm'] ?? null;
            $rawCL = $row['cutting_lebar_mm'] ?? null;
            $rawCP = $row['cutting_panjang_mm'] ?? null;
            $cutting_t = ($rawCT !== null && $rawCT !== '') ? (float) $rawCT : null;
            $cutting_l = ($rawCL !== null && $rawCL !== '') ? (float) $rawCL : null;
            $cutting_p = ($rawCP !== null && $rawCP !== '') ? (float) $rawCP : null;

            Log::info("ROW #{$index} - CUTTING READ: CT={$rawCT}, CL={$rawCL}, CP={$rawCP} → parsed: {$cutting_t}/{$cutting_l}/{$cutting_p}");
            $stokAwal = (float) $row['stok_awal'];

            $uniqueName = "{$namaDasar} ({$t}x{$l}x{$p})";

            // m3 per pcs
            $kubikasiPerPcs = ($t * $l * $p) / 1000000000;
            $totalM3 = $kubikasiPerPcs * $stokAwal;

            $specifications = [
                't' => $t,
                'l' => $l,
                'p' => $p,
                'm3_per_pcs' => $kubikasiPerPcs,
            ];

            try {
                Log::info("ROW #{$index} - ITEM: {$uniqueName}");

                // 1. Master Item
                $noRak = isset($row['no_rak']) ? trim($row['no_rak']) : null;

                $itemValues = [
                    'name'           => $uniqueName,
                    'category_id'    => $this->categoryKayu->id,
                    'unit_id'        => $unit->id,
                    'specifications' => $specifications,
                    'stock'          => $stokAwal,
                    'jenis'          => $jenis,
                    'kualitas'       => $kualitas,
                    'bentuk'         => $bentuk,
                    'volume_m3'      => $kubikasiPerPcs,
                ];

                // Hanya update cutting jika kolom ada di Excel (hindari overwrite dengan null)
                if ($cutting_t !== null) $itemValues['cutting_t'] = $cutting_t;
                if ($cutting_l !== null) $itemValues['cutting_l'] = $cutting_l;
                if ($cutting_p !== null) $itemValues['cutting_p'] = $cutting_p;
                if ($noRak     !== null) $itemValues['no_rak']    = $noRak;

                $item = Item::updateOrCreate(['code' => $kodeBarang], $itemValues);

                // 2. Stock Movement (legacy)
                if ($stokAwal > 0) {
                    $existingMovement = StockMovement::where('item_id', $item->id)
                        ->where('notes', 'LIKE', '%Saldo Awal (Kayu RST)%')
                        ->first();

                    if ($existingMovement) {
                        $existingMovement->update([
                            'quantity' => $stokAwal,
                            'notes' => 'Saldo Awal (Kayu RST) diperbarui via Excel upload.',
                        ]);
                    } else {
                        StockMovement::create([
                            'item_id' => $item->id,
                            'type' => 'Stok Masuk',
                            'quantity' => $stokAwal,
                            'notes' => 'Saldo Awal (Kayu RST) dari Excel upload.',
                        ]);
                    }
                }

                // 3. Stok per Gudang
                $warehouse = Warehouse::whereRaw('UPPER(code) = ?', [$gudangCode])->first();

                if (!$warehouse) {
                    $skippedRows[] = [
                        'row_number' => $index + 2,
                        'item_name' => $uniqueName,
                        'reason' => 'Kode gudang tidak ditemukan: ' . $gudangCodeRaw,
                    ];
                    Log::warning("ROW #{$index} - GUDANG TIDAK DITEMUKAN: {$gudangCodeRaw}");
                } else {
                    // Update tabel stocks (legacy)
                    Stock::updateOrCreate(
                        [
                            'item_id' => $item->id,
                            'warehouse_id' => $warehouse->id,
                        ],
                        [
                            'quantity' => $stokAwal,
                        ]
                    );
                    Log::info("ROW #{$index} - STOCK GUDANG OK (WH ID {$warehouse->id})");

                    // Update tabel inventories
                    Inventory::updateOrCreate(
                        [
                            'item_id' => $item->id,
                            'warehouse_id' => $warehouse->id,
                            'ref_po_id' => null,
                            'ref_product_id' => null,
                        ],
                        [
                            'qty_pcs' => $stokAwal,
                            'qty_m3' => $totalM3,
                        ]
                    );
                    Log::info("ROW #{$index} - INVENTORY SALDO AWAL SYNCED: Qty {$stokAwal}, M3 {$totalM3}");

                    // ✅ Catat ke inventory_logs
                    $existingLog = InventoryLog::where('item_id', $item->id)
                        ->where('warehouse_id', $warehouse->id)
                        ->where('transaction_type', 'INITIAL_STOCK')
                        ->first();

                    if ($existingLog) {
                        $existingLog->update([
                            'qty' => $stokAwal,
                            'qty_m3' => $totalM3,
                            'notes' => 'Saldo Awal Kayu RST diperbarui via Excel',
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
                            'reference_number' => 'IMPORT-KAYU-' . $kodeBarang,
                            'notes' => 'Saldo Awal Kayu RST dari Excel upload',
                            'user_id' => Auth::id(),
                        ]);
                        Log::info("ROW #{$index} - ✅ INVENTORY_LOG CREATED");
                    }
                }

                $processedRows++;
            } catch (\Exception $e) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'item_name' => $uniqueName,
                    'reason' => 'Error sistem: ' . $e->getMessage(),
                ];
                Log::error("Error processing row {$index} untuk kayu {$uniqueName}: " . $e->getMessage());
            }
        }

        if (!empty($skippedRows)) {
            Log::warning('Baris Excel Kayu RST yang ditolak:', $skippedRows);
        }

        Log::info("Import Kayu RST selesai. Berhasil: {$processedRows} baris. Ditolak: " . count($skippedRows) . ' baris.');
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
        ];
    }
}
