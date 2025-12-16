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
        $this->defaultCategory = Category::firstOrCreate(
            ['name' => 'Produk Jadi'],
            ['description' => 'Produk Jadi Furniture']
        );

        $this->defaultUnit = Unit::firstOrCreate(
            ['name' => 'PCS'],
            ['short_name' => 'PCS']
        );
    }

    public function collection(Collection $rows)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $userId        = Auth::id();
        $skippedRows   = [];
        $processedRows = 0;

        Log::info("========== MULAI IMPORT PRODUK JADI ==========");
        Log::info("Total rows dari Excel: " . $rows->count());

        foreach ($rows as $index => $row) {
            $rowData = $row->toArray();
            Log::info("ROW #{$index} - DATA:", $rowData);

            // wajib minimal
            if (
                empty($rowData['nama_produk']) ||
                !isset($rowData['stok_awal']) ||
                empty($rowData['gudang'])
            ) {
                Log::warning("ROW #{$index} DITOLAK (wajib kosong)");
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason'     => 'Kolom nama_produk, stok_awal, atau gudang kosong',
                ];
                continue;
            }

            $namaProduk = trim($rowData['nama_produk']);
            $kodeBarang = trim($rowData['kode_barang'] ?? '');
            $stokAwal   = (float) $rowData['stok_awal'];
            $hsCodeRaw  = $rowData['hs_code'] ?? null;

            if (empty($hsCodeRaw)) {
                Log::warning("ROW #{$index} DITOLAK - HS Code kosong");
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'item_name'  => $namaProduk,
                    'reason'     => 'HS Code wajib diisi',
                ];
                continue;
            }

            $hsCode = $this->sanitizeHsCode($hsCodeRaw);

            // kategori & satuan dari Excel (fallback ke default)
            $kategoriName = trim($rowData['kategori'] ?? '');
            $satuanName   = trim($rowData['satuan'] ?? '');

            $category = $kategoriName
                ? Category::firstOrCreate(
                    ['name' => $kategoriName],
                    ['description' => 'Kategori dari import Produk Jadi']
                  )
                : $this->defaultCategory;

            $unit = $satuanName
                ? Unit::firstOrCreate(
                    ['name' => $satuanName],
                    ['short_name' => strtoupper(substr($satuanName, 0, 5))]
                  )
                : $this->defaultUnit;

            // gudang: normalisasi code
            $gudangCodeRaw = trim($rowData['gudang']);
            $gudangCode    = strtoupper($gudangCodeRaw);

            try {
                Log::info("ROW #{$index} - CREATING ITEM: {$namaProduk}");

                // 1) master item (tanpa set kolom stock, stok dipegang tabel stocks)
                $item = Item::updateOrCreate(
                    ['code' => $kodeBarang],
                    [
                        'name'                   => $namaProduk,
                        'category_id'            => $category->id,
                        'unit_id'                => $unit->id,
                        'hs_code'                => $hsCode,
                        'nw_per_box'             => !empty($rowData['nw_per_box']) ? (float) $rowData['nw_per_box'] : null,
                        'gw_per_box'             => !empty($rowData['gw_per_box']) ? (float) $rowData['gw_per_box'] : null,
                        'wood_consumed_per_pcs'  => !empty($rowData['wood_consumed_per_pcs']) ? (float) $rowData['wood_consumed_per_pcs'] : null,
                        'm3_per_carton'          => !empty($rowData['m3_per_carton']) ? (float) $rowData['m3_per_carton'] : null,
                    ]
                );

                Log::info("ROW #{$index} - ITEM SAVED: ID {$item->id}");

                // 2) stock movement saldo awal
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
                        'reason'     => 'Kode gudang tidak ditemukan: ' . $gudangCodeRaw,
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

        Log::info("========== IMPORT PRODUK JADI SELESAI ==========");
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
