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

class ProdukJadiStockImport implements ToCollection, WithHeadingRow
{
    private $defaultCategory;
    private $defaultUnit;

    public function __construct()
    {
        // Default kategori Produk Jadi
        $this->defaultCategory = Category::firstOrCreate(
            ['name' => 'Produk Jadi'],
            ['description' => 'Produk Jadi Furniture']
        );

        // Default satuan PCS (pakai short_name sebagai key unik)
        $this->defaultUnit = Unit::firstOrCreate(
            ['short_name' => 'PCS'],
            [
                'name'        => 'PCS',
                'description' => 'Pieces',
            ]
        );
    }

    public function collection(Collection $rows)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $skippedRows   = [];
        $processedRows = 0;

        Log::info('========== MULAI IMPORT PRODUK JADI ==========');
        Log::info('Total rows dari Excel: ' . $rows->count());

        foreach ($rows as $index => $row) {
            $rowData = $row->toArray();
            Log::info("ROW #{$index} - RAW DATA:", $rowData);

            // Normalisasi key ke lower-case untuk jaga-jaga header beda kapital
            $normalized = [];
            foreach ($rowData as $key => $value) {
                $normalized[strtolower(trim($key))] = $value;
            }

            // Ambil kolom dengan nama yang lebih fleksibel
            $namaProduk = trim($normalized['nama_produk'] ?? $normalized['nama produk'] ?? '');
            $kodeBarang = trim($normalized['kode_barang'] ?? $normalized['kode barang'] ?? '');
            $stokAwal   = isset($normalized['stok_awal'])
                ? (float) $normalized['stok_awal']
                : (isset($normalized['stok awal']) ? (float) $normalized['stok awal'] : 0);
            $gudangRaw  = trim($normalized['gudang'] ?? '');
            $hsCodeRaw  = $normalized['hs_code'] ?? $normalized['hs code'] ?? null;

            // Wajib minimal: nama produk, stok awal, gudang
            if (empty($namaProduk) || $stokAwal <= 0 || empty($gudangRaw)) {
                Log::warning("ROW #{$index} DITOLAK (kolom wajib kosong / stok <= 0)");
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason'     => 'Kolom nama_produk, stok_awal, atau gudang kosong / stok <= 0',
                    'data'       => $normalized,
                ];
                continue;
            }

            // HS Code: kalau kosong, tidak lagi menggagalkan baris, hanya dicatat null
            $hsCode = null;
            if (!empty($hsCodeRaw)) {
                $hsCode = $this->sanitizeHsCode((string) $hsCodeRaw);
            } else {
                Log::warning("ROW #{$index} - HS Code kosong, item tetap dibuat tanpa HS Code.");
            }

            // kategori & satuan dari Excel (fallback ke default)
            $kategoriName = trim($normalized['kategori'] ?? '');
            $satuanName   = trim($normalized['satuan'] ?? '');

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
                        'name'        => $satuanName,
                        'description' => 'Satuan dari import Produk Jadi',
                    ]
                );
            } else {
                $unit = $this->defaultUnit;
            }

            // Gudang: normalisasi code ke uppercase
            $gudangCode    = strtoupper($gudangRaw);

            try {
                Log::info("ROW #{$index} - CREATING/UPDATING ITEM: {$namaProduk}");

                // Kolom-kolom tambahan DNA
                $nwPerBox   = $normalized['nw_per_box'] ?? $normalized['nw per box'] ?? null;
                $gwPerBox   = $normalized['gw_per_box'] ?? $normalized['gw per box'] ?? null;
                $woodPerPcs = $normalized['wood_consumed_per_pcs'] ?? $normalized['wood consumed per pcs'] ?? null;
                $m3Carton   = $normalized['m3_per_carton'] ?? $normalized['m3 per carton'] ?? null;

                // 1) master item
                $item = Item::updateOrCreate(
                    ['code' => $kodeBarang],
                    [
                        'name'                  => $namaProduk,
                        'category_id'           => $category->id,
                        'unit_id'               => $unit->id,
                        'hs_code'               => $hsCode,
                        'nw_per_box'            => $nwPerBox !== null ? (float) $nwPerBox : null,
                        'gw_per_box'            => $gwPerBox !== null ? (float) $gwPerBox : null,
                        'wood_consumed_per_pcs' => $woodPerPcs !== null ? (float) $woodPerPcs : null,
                        'm3_per_carton'         => $m3Carton !== null ? (float) $m3Carton : null,
                    ]
                );

                Log::info("ROW #{$index} - ITEM SAVED: ID {$item->id}");

                // 2) stock movement saldo awal (global)
                if ($stokAwal > 0) {
                    $existingMovement = StockMovement::where('item_id', $item->id)
                        ->where('notes', 'LIKE', '%Saldo Awal Produk Jadi%')
                        ->first();

                    if ($existingMovement) {
                        $existingMovement->update([
                            'quantity' => $stokAwal,
                            'notes'    => 'Saldo Awal Produk Jadi diperbarui via Excel upload.',
                        ]);
                        Log::info("ROW #{$index} - MOVEMENT UPDATED");
                    } else {
                        StockMovement::create([
                            'item_id'  => $item->id,
                            'type'     => 'Stok Masuk',
                            'quantity' => $stokAwal,
                            'notes'    => 'Saldo Awal Produk Jadi dari Excel upload.',
                        ]);
                        Log::info("ROW #{$index} - MOVEMENT CREATED");
                    }
                }

                // 3) stok per gudang
                $warehouse = Warehouse::whereRaw('UPPER(code) = ?', [$gudangCode])->first();

                if (!$warehouse) {
                    $skippedRows[] = [
                        'row_number' => $index + 2,
                        'item_name'  => $namaProduk,
                        'reason'     => 'Kode gudang tidak ditemukan: ' . $gudangRaw,
                    ];
                    Log::warning("ROW #{$index} - GUDANG TIDAK DITEMUKAN: {$gudangRaw}");
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
                    Log::info("ROW #{$index} - STOCK PER GUDANG DISIMPAN (WH ID {$warehouse->id})");
                }

                $processedRows++;
                Log::info("ROW #{$index} - SUCCESS!");
            } catch (\Exception $e) {
                Log::error("ROW #{$index} - ERROR: " . $e->getMessage());

                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'item_name'  => $namaProduk,
                    'reason'     => 'Error: ' . $e->getMessage(),
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
