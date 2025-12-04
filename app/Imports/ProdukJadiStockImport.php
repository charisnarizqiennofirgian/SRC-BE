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

class ProdukJadiStockImport implements ToCollection, WithHeadingRow
{
    private $categoryProdukJadi;
    private $unitPieces;

    public function __construct()
    {
        $this->categoryProdukJadi = Category::firstOrCreate(
            ['name' => 'Produk Jadi'],
            ['description' => 'Produk Jadi Furniture']
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

        Log::info("========== MULAI IMPORT PRODUK JADI ==========");
        Log::info("Total rows dari Excel: " . $rows->count());

        foreach ($rows as $index => $row) 
        {
            // ✅ FIX: Convert Collection ke Array
            $rowData = $row->toArray();
            
            Log::info("ROW #{$index} - DATA:", $rowData);
            
            if (empty($rowData['nama_produk']) || !isset($rowData['stok_awal'])) {
                Log::warning("ROW #{$index} DITOLAK");
                
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'reason' => 'Kolom nama_produk atau stok_awal kosong'
                ];
                continue;
            }

            $namaProduk = trim($rowData['nama_produk']);
            $kodeBarang = trim($rowData['kode_barang'] ?? '');
            $stokAwal = (float) $rowData['stok_awal'];
            $hsCode = !empty($rowData['hs_code']) ? trim($rowData['hs_code']) : null;

            if (empty($hsCode)) {
                Log::warning("ROW #{$index} DITOLAK - HS Code kosong");
                
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'item_name' => $namaProduk,
                    'reason' => "HS Code wajib diisi"
                ];
                continue;
            }

            $hsCode = $this->sanitizeHsCode($hsCode);

            try {
                Log::info("ROW #{$index} - CREATING ITEM: {$namaProduk}");
                
                $item = Item::updateOrCreate(
                    ['code' => $kodeBarang],
                    [
                        'name' => $namaProduk,
                        'category_id' => $this->categoryProdukJadi->id,
                        'unit_id' => $this->unitPieces->id,
                        'hs_code' => $hsCode, 
                        'nw_per_box' => !empty($rowData['nw_per_box']) ? (float) $rowData['nw_per_box'] : null,
                        'gw_per_box' => !empty($rowData['gw_per_box']) ? (float) $rowData['gw_per_box'] : null,
                        'wood_consumed_per_pcs' => !empty($rowData['wood_consumed_per_pcs']) ? (float) $rowData['wood_consumed_per_pcs'] : null,
                        'm3_per_carton' => !empty($rowData['m3_per_carton']) ? (float) $rowData['m3_per_carton'] : null,
                        'stock' => $stokAwal,
                    ]
                );

                Log::info("ROW #{$index} - ITEM SAVED: ID {$item->id}");

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

                $processedRows++;
                Log::info("ROW #{$index} - SUCCESS!");
                
            } catch (\Exception $e) {
                Log::error("ROW #{$index} - ERROR: " . $e->getMessage());
                
                $skippedRows[] = [
                    'row_number' => $index + 2,
                    'item_name' => $namaProduk,
                    'reason' => 'Error: ' . $e->getMessage()
                ];
            }
        }

        if (!empty($skippedRows)) {
            Log::warning('Baris ditolak:', $skippedRows);
        }
        
        Log::info("========== IMPORT SELESAI ==========");
        Log::info("Berhasil: {$processedRows}, Ditolak: " . count($skippedRows));
    }

    private function sanitizeHsCode(string $hsCode): string
    {
        $clean = preg_replace('/[^0-9.]/', '', (string)$hsCode);
        
        if (strpos($clean, '.') === false && strlen($clean) >= 6) {
            $clean = substr($clean, 0, 4) . '.' . substr($clean, 4);
        }
        
        return $clean;
    }
}
