<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Warehouse;
use App\Models\InventoryLog;
use App\Models\KdProduction;
use App\Models\ProductionOrder;
use App\Services\ProductionOrderProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CandyProductionController extends Controller
{
    protected ProductionOrderProgressService $poProgress;

    public function __construct(ProductionOrderProgressService $poProgress)
    {
        $this->poProgress = $poProgress;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date'                  => ['required', 'date'],
            'estimated_finish_date' => ['nullable', 'date', 'after_or_equal:date'],
            'notes'                 => ['nullable', 'string'],
            'ref_po_id'             => ['required', 'integer', 'exists:production_orders,id'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.warehouse_id'  => ['required', 'integer', 'exists:warehouses,id'],
            'items.*.item_id'       => ['required', 'integer', 'exists:items,id'],
            'items.*.target_item_id'=> ['required', 'integer', 'exists:items,id'],
            'items.*.qty'           => ['required', 'integer', 'min:1'],
        ]);

        Log::info('=== KD PRODUCTION START ===');

        return DB::transaction(function () use ($data) {

            // === GUDANG KD ===
            $kdWarehouse = Warehouse::where('code', 'RSTK')->first();
            if (!$kdWarehouse) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang KD (RST Kering) tidak ditemukan di master.'],
                ]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? 'TANPA-PO';

            // === NOMOR DOKUMEN ===
            $runningNumber  = KdProduction::whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->count() + 1;
            $documentNumber = 'KD-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            // === SIMPAN HEADER KD ===
            $kdProduction = KdProduction::create([
                'document_number'       => $documentNumber,
                'date'                  => $data['date'],
                'estimated_finish_date' => $data['estimated_finish_date'] ?? null,
                'ref_po_id'             => $data['ref_po_id'],
                'notes'                 => $data['notes'] ?? null,
                'created_by'            => Auth::id(),
            ]);

            // === PROSES SETIAP ITEM ===
            foreach ($data['items'] as $index => $itemData) {
                $sourceWarehouseId = $itemData['warehouse_id'];
                $sourceItemId      = $itemData['item_id'];
                $targetItemId      = $itemData['target_item_id'];
                $qty               = $itemData['qty'];

                Log::info("Item #{$index}: source={$sourceItemId}, target={$targetItemId}, qty={$qty}");

                // Cek stok di gudang asal
                $sourceInventory = Inventory::where('item_id', $sourceItemId)
                    ->where('warehouse_id', $sourceWarehouseId)
                    ->lockForUpdate()
                    ->first();

                $availableQty = $sourceInventory?->qty_pcs ?? 0;

                if ($availableQty < $qty) {
                    $itemName = \App\Models\Item::find($sourceItemId)?->name ?? "ID {$sourceItemId}";
                    throw ValidationException::withMessages([
                        'items' => ["Stok '{$itemName}' tidak mencukupi. Tersedia: {$availableQty} pcs, dibutuhkan: {$qty} pcs."],
                    ]);
                }

                // Kurangi stok di gudang asal (OUT)
                $sourceInventory->decrement('qty_pcs', $qty);

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $sourceItemId,
                    'warehouse_id'     => $sourceWarehouseId,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'OUT',
                    'transaction_type' => 'KD',
                    'reference_type'   => 'KDProduction',
                    'reference_id'     => $kdProduction->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "RST Basah masuk oven KD ({$documentNumber})",
                    'user_id'          => Auth::id(),
                ]);

                // Tambah stok di Gudang KD (IN)
                $targetInventory = Inventory::where('item_id', $targetItemId)
                    ->where('warehouse_id', $kdWarehouse->id)
                    ->lockForUpdate()
                    ->first();

                if ($targetInventory) {
                    $targetInventory->increment('qty_pcs', $qty);
                } else {
                    Inventory::create([
                        'item_id'      => $targetItemId,
                        'warehouse_id' => $kdWarehouse->id,
                        'qty_pcs'      => $qty,
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $targetItemId,
                    'warehouse_id'     => $kdWarehouse->id,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'IN',
                    'transaction_type' => 'KD',
                    'reference_type'   => 'KDProduction',
                    'reference_id'     => $kdProduction->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Hasil oven KD RST kering ({$documentNumber})",
                    'user_id'          => Auth::id(),
                ]);
            }

            // === UPDATE STAGE PO ===
            if ($productionOrder) {
                $this->poProgress->markOnProgress($productionOrder->id);
            }

            Log::info('=== KD PRODUCTION END ===', ['doc' => $documentNumber]);

            return response()->json([
                'success' => true,
                'message' => "Proses KD berhasil dicatat ({$documentNumber})",
                'data'    => [
                    'id'                    => $kdProduction->id,
                    'document_number'       => $documentNumber,
                    'estimated_finish_date' => $kdProduction->estimated_finish_date,
                ],
            ], 201);
        });
    }
}