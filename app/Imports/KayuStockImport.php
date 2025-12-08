<?php

namespace App\Imports;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

class KayuStockImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{
    private $categoryKayu;
    private $unitPieces;

    public function __construct()
    {
        $this->categoryKayu = Category::firstOrCreate(
            ['name' => 'Kayu RST'],
            ['description' => 'Bahan Baku Kayu RST']
        );

        $this->unitPieces = Unit::firstOrCreate(
            ['name' => 'Pieces'],
            ['short_name' => 'PCS']
        );
    }

    public function collection(Collection $rows)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $userId = Auth::id();
        $skippedRows = [];
        $processedRows = 0;

        foreach ($rows as $index => $row) {
            // ✅ VALIDASI WAJIB MINIMAL
            if (empty($row['nama_dasar']) || empty($row['tebal_mm']) || !isset($row['stok_awal'])) {
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason' => 'Kolom nama_dasar, tebal_mm, atau stok_awal kosong',
                ];
                continue;
            }

            $namaDasar  = trim($row['nama_dasar']);
            $kodeBarang = trim($row['kode_barang'] ?? '');

            // 🔽 FIELD BARU DARI EXCEL
            $jenis    = isset($row['jenis']) ? trim($row['jenis']) : null;
            $kualitas = isset($row['kualitas']) ? trim($row['kualitas']) : null;
            $bentuk   = isset($row['bentuk']) ? trim($row['bentuk']) : null;

            $t = (float) $row['tebal_mm'];
            $l = (float) ($row['lebar_mm'] ?? 0);
            $p = (float) ($row['panjang_mm'] ?? 0);
            $stokAwal = (float) $row['stok_awal'];

            // Nama unik tetap seperti sebelum
            $uniqueName = "{$namaDasar} ({$t}x{$l}x{$p})";

            // Kubikasi per pcs (m3)
            $kubikasi = ($t * $l * $p) / 1000000000;

            $specifications = [
                't' => $t,
                'l' => $l,
                'p' => $p,
                'm3_per_pcs' => $kubikasi,
            ];

            try {
                // ✅ 1. CREATE/UPDATE ITEM DENGAN STOK + FIELD KAYU RST
                $item = Item::updateOrCreate(
                    ['name' => $uniqueName],
                    [
                        'code'          => $kodeBarang,
                        'category_id'   => $this->categoryKayu->id,
                        'unit_id'       => $this->unitPieces->id,
                        'specifications'=> $specifications,
                        'stock'         => $stokAwal,
                        'jenis'         => $jenis,     // ✅ SIMPAN
                        'kualitas'      => $kualitas,  // ✅ SIMPAN
                        'bentuk'        => $bentuk,    // ✅ SIMPAN
                    ]
                );

                // ✅ 2. BIKIN / UPDATE STOCK MOVEMENT (Saldo Awal Kayu)
                if ($stokAwal > 0) {
                    $existingMovement = StockMovement::where('item_id', $item->id)
                        ->where('notes', 'LIKE', '%Saldo Awal (Kayu)%')
                        ->first();

                    if ($existingMovement) {
                        $existingMovement->update([
                            'quantity' => $stokAwal,
                            'notes' => 'Saldo Awal (Kayu) diperbarui via Excel upload.',
                        ]);
                    } else {
                        StockMovement::create([
                            'item_id' => $item->id,
                            'type' => 'Stok Masuk',
                            'quantity' => $stokAwal,
                            'notes' => 'Saldo Awal (Kayu) dari Excel upload.',
                        ]);
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
            Log::warning('Baris Excel Kayu yang ditolak:', $skippedRows);
        }

        Log::info("Import Kayu selesai. Berhasil: {$processedRows} baris. Ditolak: " . count($skippedRows) . " baris.");
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
        ];
    }
}
