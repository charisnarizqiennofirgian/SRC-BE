<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\Material;
use App\Models\Item;
use App\Models\Warehouse;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\StockAdjustment;

class StockAdjustmentImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{
    public function collection(Collection $rows)
    {
        DB::transaction(function () use ($rows) {
            foreach ($rows as $index => $row) {
                try {
                    if (empty($row['kode_barang']) || !isset($row['jumlah_stok_baru'])) {
                        Log::warning("ROW #{$index} - DITOLAK: kode_barang atau jumlah_stok_baru kosong");
                        continue;
                    }

                    $kodeBarang = trim($row['kode_barang']);
                    $quantity = (float) $row['jumlah_stok_baru'];
                    $gudangCode = strtoupper(trim($row['gudang'] ?? ''));
                    $notes = trim($row['catatan'] ?? 'Stok awal diatur via upload Excel.');

                    $item = null;
                    $modelClass = null;

                    // Cari di tabel items (unified)
                    $item = Item::where('code', $kodeBarang)->first();
                    if ($item) {
                        $modelClass = Item::class;
                    } else {
                        // Fallback: cari di Product
                        $item = Product::where('code', $kodeBarang)->first();
                        if ($item) {
                            $modelClass = Product::class;
                        } else {
                            // Fallback: cari di Material
                            $item = Material::where('code', $kodeBarang)->first();
                            if ($item) {
                                $modelClass = Material::class;
                            }
                        }
                    }

                    if (!$item || !$modelClass) {
                        Log::warning("ROW #{$index} - DITOLAK: Item tidak ditemukan", ['kode' => $kodeBarang]);
                        continue;
                    }

                    // Hitung selisih
                    $oldStock = (float) $item->stock;
                    $quantityDifference = $quantity - $oldStock;

                    // Update stock di item
                    $item->stock = $quantity;
                    $item->save();

                    Log::info("ROW #{$index} - ITEM UPDATED: {$item->name} (Stock: {$oldStock} → {$quantity})");

                    // Buat StockAdjustment record (legacy)
                    StockAdjustment::create([
                        'adjustable_id' => $item->id,
                        'adjustable_type' => $modelClass,
                        'type' => 'Stok Awal (Upload)',
                        'quantity' => $quantityDifference,
                        'notes' => $notes,
                        'user_id' => Auth::id(),
                    ]);

                    // ✅ Update Inventory per gudang (jika ada kolom gudang)
                    $warehouseId = null;
                    if ($gudangCode) {
                        $warehouse = Warehouse::whereRaw('UPPER(code) = ?', [$gudangCode])->first();
                        if ($warehouse) {
                            $warehouseId = $warehouse->id;
                        }
                    }

                    // Jika tidak ada gudang di Excel, coba cari inventory yang sudah ada
                    if (!$warehouseId) {
                        $existingInventory = Inventory::where('item_id', $item->id)->first();
                        if ($existingInventory) {
                            $warehouseId = $existingInventory->warehouse_id;
                        }
                    }

                    if ($warehouseId) {
                        // Cek stok lama di inventory
                        $oldInventory = Inventory::where('item_id', $item->id)
                            ->where('warehouse_id', $warehouseId)
                            ->first();
                        $oldQtyInventory = $oldInventory ? (float) $oldInventory->qty : 0;

                        // Update inventory
                        Inventory::updateOrCreate(
                            [
                                'item_id'      => $item->id,
                                'warehouse_id' => $warehouseId,
                            ],
                            [
                                'qty' => $quantity,
                            ]
                        );

                        Log::info("ROW #{$index} - ✅ INVENTORY UPDATED: {$quantity} (Warehouse ID: {$warehouseId})");

                        // ✅ Catat ke inventory_logs sebagai ADJUSTMENT
                        $diff = $quantity - $oldQtyInventory;
                        if ($diff != 0) {
                            InventoryLog::create([
                                'date'             => now()->toDateString(),
                                'time'             => now()->toTimeString(),
                                'item_id'          => $item->id,
                                'warehouse_id'     => $warehouseId,
                                'qty'              => abs($diff),
                                'direction'        => $diff > 0 ? 'IN' : 'OUT',
                                'transaction_type' => 'ADJUSTMENT',
                                'reference_type'   => 'StockAdjustment',
                                'reference_id'     => $item->id,
                                'reference_number' => 'ADJ-IMPORT-' . $kodeBarang,
                                'notes'            => $notes . " (Perubahan: {$oldQtyInventory} → {$quantity})",
                                'user_id'          => Auth::id(),
                            ]);
                            Log::info("ROW #{$index} - ✅ INVENTORY_LOG CREATED: {$diff} ({$diff > 0 ? 'IN' : 'OUT'})");
                        }
                    } else {
                        Log::warning("ROW #{$index} - Gudang tidak ditemukan, inventory tidak diupdate");
                    }

                } catch (\Throwable $e) {
                    Log::error(
                        'Error import Stock Adjustment di baris ' . ($index + 2) . ': ' . $e->getMessage(),
                        ['row_data' => $row->toArray()]
                    );
                }
            }
        });
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
        ];
    }
}
