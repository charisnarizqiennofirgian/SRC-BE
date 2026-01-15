<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Warehouse;
use App\Models\InventoryLog;
use App\Models\ProductionOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class OperatorMesinController extends Controller
{
    public function produce(Request $request)
    {
        Log::info('=== MESIN START ===');
        Log::info('Payload: ' . json_encode($request->all()));

        $validator = Validator::make($request->all(), [
            'production_order_id' => ['required', 'integer', 'exists:production_orders,id'],
            'materials' => ['required', 'array', 'min:1'],
            'materials.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'materials.*.qty' => ['required', 'numeric', 'min:0.001'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'components.*.qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        if ($validator->fails()) {
            Log::error('Validasi gagal: ' . json_encode($validator->errors()));
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Ambil data PO untuk reference
        $productionOrder = ProductionOrder::find($data['production_order_id']);
        $poNumber = $productionOrder?->po_number;

        // Ambil warehouse
        $warehouseMoulding = Warehouse::where('name', 'LIKE', '%Moulding%')->first();
        $warehouseKomponen = Warehouse::where(function($q) {
            $q->where('name', 'LIKE', '%Komponen%')
              ->orWhere('name', 'LIKE', '%Mesin%')
              ->orWhere('name', 'LIKE', '%Komponen Mesin%');
        })->first();

        Log::info('Warehouse Moulding: ' . ($warehouseMoulding ? $warehouseMoulding->id . ' - ' . $warehouseMoulding->name : 'NOT FOUND'));
        Log::info('Warehouse Komponen: ' . ($warehouseKomponen ? $warehouseKomponen->id . ' - ' . $warehouseKomponen->name : 'NOT FOUND'));

        if (!$warehouseMoulding) {
            Log::error('Gudang Moulding tidak ditemukan!');
            return response()->json([
                'success' => false,
                'message' => 'Gudang Moulding tidak ditemukan di master warehouse!',
            ], 422);
        }

        if (!$warehouseKomponen) {
            Log::error('Gudang Komponen tidak ditemukan!');
            return response()->json([
                'success' => false,
                'message' => 'Gudang Komponen/Mesin tidak ditemukan di master warehouse!',
            ], 422);
        }

        $warehouseMouldingId = $warehouseMoulding->id;
        $warehouseKomponenId = $warehouseKomponen->id;

        DB::beginTransaction();

        try {
            // STEP 1: Kurangi stok bahan baku (kayu RST)
            Log::info('--- STEP 1: KURANGI STOK KAYU RST ---');

            foreach ($data['materials'] as $index => $material) {
                $itemId = $material['item_id'];
                $qtyUsed = $material['qty'];

                Log::info("Material #{$index}: Item ID {$itemId}, Qty {$qtyUsed}");

                $inventories = Inventory::where('warehouse_id', $warehouseMouldingId)
                    ->where('item_id', $itemId)
                    ->where('qty', '>', 0)
                    ->lockForUpdate()
                    ->get();

                Log::info("Found " . $inventories->count() . " inventory rows for item {$itemId}");

                if ($inventories->isEmpty()) {
                    DB::rollBack();
                    Log::error("Stok kayu ID {$itemId} tidak ditemukan!");
                    return response()->json([
                        'success' => false,
                        'message' => "Stok kayu ID {$itemId} tidak ditemukan di Gudang Moulding!",
                    ], 422);
                }

                $totalAvailable = $inventories->sum('qty');
                Log::info("Total stok tersedia: {$totalAvailable}");

                if ($totalAvailable < $qtyUsed) {
                    DB::rollBack();
                    $itemName = $inventories->first()->item->name ?? "Item ID {$itemId}";
                    Log::error("Stok tidak cukup! Tersedia: {$totalAvailable}, Dibutuhkan: {$qtyUsed}");
                    return response()->json([
                        'success' => false,
                        'message' => "Stok {$itemName} tidak cukup! (Tersedia: {$totalAvailable}, Dibutuhkan: {$qtyUsed})",
                    ], 422);
                }

                // Kurangi stok
                $remaining = $qtyUsed;
                foreach ($inventories as $inventory) {
                    if ($remaining <= 0) break;

                    $currentQty = $inventory->qty;
                    $qtyToTake = min($remaining, $currentQty);

                    if ($inventory->qty >= $remaining) {
                        Log::info("Taking {$remaining} pcs from Inventory ID {$inventory->id} (current qty: {$currentQty})");
                        $inventory->decrement('qty', $remaining);
                        Log::info("Inventory ID {$inventory->id} updated to " . ($currentQty - $remaining) . " pcs");

                        // Catat ke inventory_logs (OUT dari Gudang Moulding)
                        InventoryLog::create([
                            'date' => now()->toDateString(),
                            'time' => now()->toTimeString(),
                            'item_id' => $itemId,
                            'warehouse_id' => $warehouseMouldingId,
                            'qty' => $remaining,
                            'direction' => 'OUT',
                            'transaction_type' => 'PRODUCTION',
                            'reference_type' => 'ProductionOrder',
                            'reference_id' => $data['production_order_id'],
                            'reference_number' => $poNumber,
                            'notes' => "Bahan kayu untuk proses Mesin/Komponen",
                            'user_id' => Auth::id(),
                        ]);

                        $remaining = 0;
                    } else {
                        Log::info("Taking {$inventory->qty} pcs from Inventory ID {$inventory->id} (current qty: {$currentQty})");

                        // Catat ke inventory_logs (OUT dari Gudang Moulding)
                        InventoryLog::create([
                            'date' => now()->toDateString(),
                            'time' => now()->toTimeString(),
                            'item_id' => $itemId,
                            'warehouse_id' => $warehouseMouldingId,
                            'qty' => $inventory->qty,
                            'direction' => 'OUT',
                            'transaction_type' => 'PRODUCTION',
                            'reference_type' => 'ProductionOrder',
                            'reference_id' => $data['production_order_id'],
                            'reference_number' => $poNumber,
                            'notes' => "Bahan kayu untuk proses Mesin/Komponen",
                            'user_id' => Auth::id(),
                        ]);

                        $remaining -= $inventory->qty;
                        $inventory->update(['qty' => 0]);
                        Log::info("Inventory ID {$inventory->id} updated to 0 pcs");
                    }
                }
            }

            // STEP 2: Tambah stok komponen
            Log::info('--- STEP 2: TAMBAH STOK KOMPONEN ---');

            foreach ($data['components'] as $index => $component) {
                $itemId = $component['item_id'];
                $qtyProduced = $component['qty'];

                Log::info("Component #{$index}: Item ID {$itemId}, Qty {$qtyProduced}");

                $inventory = Inventory::where('warehouse_id', $warehouseKomponenId)
                    ->where('item_id', $itemId)
                    ->where('ref_po_id', $data['production_order_id'])
                    ->first();

                if ($inventory) {
                    $oldQty = $inventory->qty;
                    $inventory->increment('qty', $qtyProduced);
                    Log::info("Inventory ID {$inventory->id} incremented from {$oldQty} to " . ($oldQty + $qtyProduced));
                } else {
                    $newInventory = Inventory::create([
                        'warehouse_id' => $warehouseKomponenId,
                        'item_id' => $itemId,
                        'qty' => $qtyProduced,
                        'ref_po_id' => $data['production_order_id'],
                    ]);
                    Log::info("New Inventory ID {$newInventory->id} created with qty {$qtyProduced}");
                }

                // Catat ke inventory_logs (IN ke Gudang Komponen/Mesin)
                InventoryLog::create([
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'item_id' => $itemId,
                    'warehouse_id' => $warehouseKomponenId,
                    'qty' => $qtyProduced,
                    'direction' => 'IN',
                    'transaction_type' => 'PRODUCTION',
                    'reference_type' => 'ProductionOrder',
                    'reference_id' => $data['production_order_id'],
                    'reference_number' => $poNumber,
                    'notes' => "Hasil produksi komponen Mesin",
                    'user_id' => Auth::id(),
                ]);
            }

            DB::commit();
            Log::info('=== MESIN END (SUCCESS) ===');

            return response()->json([
                'success' => true,
                'message' => '✅ Hasil produksi berhasil disimpan! Stok RST berkurang, Komponen bertambah.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('=== MESIN ERROR ===');
            Log::error('Error: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
