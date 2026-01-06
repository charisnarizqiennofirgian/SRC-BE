<?php

namespace App\Imports;

use App\Models\Item;
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

class KayuStockImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{
    private $categoryKayu;
    private $defaultUnit;

    public function __construct()
    {
        $this->categoryKayu = Category::firstOrCreate(
            ['name' => 'Kayu RST'],
            ['description' => 'Bahan Baku Kayu RST']
        );

        // fallback kalau kolom satuan kosong
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

        Log::info('=== MULAI IMPORT KAYU RST ===');
        Log::info('Total rows: '.$rows->count());

        foreach ($rows as $index => $row) {
            // wajib: nama_dasar, tebal_mm, stok_awal, gudang
            if (
                empty($row['nama_dasar']) ||
                empty($row['tebal_mm']) ||
                !isset($row['stok_awal']) ||
                empty($row['gudang'])
            ) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason'     => 'Kolom nama_dasar, tebal_mm, stok_awal, atau gudang kosong',
                ];
                continue;
            }

            $namaDasar  = trim($row['nama_dasar']);
            $kodeBarang = trim($row['kode_barang'] ?? '');

            // satuan dari Excel
            $satuanName = trim($row['satuan'] ?? '');
            $unit = $satuanName
                ? Unit::firstOrCreate(
                    ['name' => $satuanName],
                    ['short_name' => strtoupper(substr($satuanName, 0, 5))]
                  )
                : $this->defaultUnit;

            // gudang → uppercase
            $gudangCodeRaw = trim($row['gudang']);
            $gudangCode    = strtoupper($gudangCodeRaw);

            $jenis    = isset($row['jenis']) ? trim($row['jenis']) : null;
            $kualitas = isset($row['kualitas']) ? trim($row['kualitas']) : null;
            $bentuk   = isset($row['bentuk']) ? trim($row['bentuk']) : null;

            $t        = (float) $row['tebal_mm'];
            $l        = (float) ($row['lebar_mm'] ?? 0);
            $p        = (float) ($row['panjang_mm'] ?? 0);
            $stokAwal = (float) $row['stok_awal'];

            $uniqueName = "{$namaDasar} ({$t}x{$l}x{$p})";

            // m3 per pcs
            $kubikasiPerPcs = ($t * $l * $p) / 1000000000;

            $specifications = [
                't'          => $t,
                'l'          => $l,
                'p'          => $p,
                'm3_per_pcs' => $kubikasiPerPcs,
            ];

            try {
                Log::info("ROW #{$index} - ITEM: {$uniqueName}");

                // 1. ITEM Kayu RST
                $item = Item::updateOrCreate(
                    ['name' => $uniqueName],
                    [
                        'code'           => $kodeBarang,
                        'category_id'    => $this->categoryKayu->id,
                        'unit_id'        => $unit->id,
                        'specifications' => $specifications,
                        'jenis'          => $jenis,
                        'kualitas'       => $kualitas,
                        'bentuk'         => $bentuk,
                         'volume_m3'      => $kubikasiPerPcs,
                    ]
                );

                // 2. STOCK MOVEMENT saldo awal
                if ($stokAwal > 0) {
                    $existingMovement = StockMovement::where('item_id', $item->id)
                        ->where('notes', 'LIKE', '%Saldo Awal (Kayu RST)%')
                        ->first();

                    if ($existingMovement) {
                        $existingMovement->update([
                            'quantity' => $stokAwal,
                            'notes'    => 'Saldo Awal (Kayu RST) diperbarui via Excel upload.',
                        ]);
                    } else {
                        StockMovement::create([
                            'item_id'  => $item->id,
                            'type'     => 'Stok Masuk',
                            'quantity' => $stokAwal,
                            'notes'    => 'Saldo Awal (Kayu RST) dari Excel upload.',
                        ]);
                    }
                }

                // 3. STOK PER GUDANG
                $warehouse = Warehouse::whereRaw('UPPER(code) = ?', [$gudangCode])->first();

                if (!$warehouse) {
                    $skippedRows[] = [
                        'row_number' => $index + 2,
                        'item_name'  => $uniqueName,
                        'reason'     => 'Kode gudang tidak ditemukan: '.$gudangCodeRaw,
                    ];
                    Log::warning("ROW #{$index} - GUDANG TIDAK DITEMUKAN: {$gudangCodeRaw}");
                } else {
                    Stock::updateOrCreate(
                        [
                            'item_id'      => $item->id,
                            'warehouse_id' => $warehouse->id,
                        ],
                        [
                            'quantity' => $stokAwal,
                        ]
                    );
                    Log::info("ROW #{$index} - STOCK GUDANG OK (WH ID {$warehouse->id})");
                }

                $processedRows++;
            } catch (\Exception $e) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'item_name'  => $uniqueName,
                    'reason'     => 'Error sistem: '.$e->getMessage(),
                ];
                Log::error("Error processing row {$index} untuk kayu {$uniqueName}: ".$e->getMessage());
            }
        }

        if (!empty($skippedRows)) {
            Log::warning('Baris Excel Kayu RST yang ditolak:', $skippedRows);
        }

        Log::info("Import Kayu RST selesai. Berhasil: {$processedRows} baris. Ditolak: ".count($skippedRows).' baris.');
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
        ];
    }
}
