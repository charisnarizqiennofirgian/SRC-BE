<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\PembahananProduction;
use App\Models\PembahananProductionItem;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use App\Services\ProductionOrderProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PembahananController extends Controller
{
    protected ProductionOrderProgressService $poProgress;

    public function __construct(ProductionOrderProgressService $poProgress)
    {
        $this->poProgress = $poProgress;
    }

    // =============================================
    // GET: Available POs untuk dropdown
    // =============================================
    public function getAvailableProductionOrders(Request $request)
    {
        $pos = ProductionOrder::whereIn('current_stage', ['sawmill', 'pembahanan', 'pending'])
            ->where('status', '!=', 'completed')
            ->with(['salesOrder.buyer'])
            ->get()
            ->map(function ($po) {
                return [
                    'id'         => $po->id,
                    'po_number'  => $po->po_number,
                    'label'      => $po->po_number,
                    'buyer_name' => $po->salesOrder?->buyer?->name ?? '-',
                    'so_number'  => $po->salesOrder?->so_number ?? '-',
                ];
            });

        return response()->json(['success' => true, 'data' => $pos]);
    }

    // =============================================
    // GET: Stok tersedia di gudang sumber
    // =============================================
    public function sourceInventories(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        $inventories = Inventory::where('warehouse_id', $request->warehouse_id)
            ->where('qty_pcs', '>', 0)
            ->with(['item', 'warehouse'])
            ->orderBy('item_id')
            ->get()
            ->map(function ($inv) {
                return [
                    'id'            => $inv->id,
                    'item_id'       => $inv->item_id,
                    'item_code'     => $inv->item?->code ?? '-',
                    'item_name'     => $inv->item?->name ?? '-',
                    'warehouse_id'  => $inv->warehouse_id,
                    'warehouse_name'=> $inv->warehouse?->name ?? '-',
                    'qty_pcs'       => (float) $inv->qty_pcs,
                ];
            });

        return response()->json(['success' => true, 'data' => $inventories]);
    }

    // =============================================
    // POST: Simpan proses pembahanan
    // =============================================
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'                  => ['required', 'date'],
            'estimated_finish_date' => ['nullable', 'date', 'after_or_equal:date'],
            'ref_po_id'             => ['required', 'integer', 'exists:production_orders,id'],
            'source_warehouse_id'   => ['required', 'integer', 'exists:warehouses,id'],
            'notes'                 => ['nullable', 'string'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.item_id'       => ['required', 'integer', 'exists:items,id'],
            'items.*.qty'           => ['required', 'numeric', 'min:1'],
        ]);

        return DB::transaction(function () use ($data) {
            Log::info('=== PEMBAHANAN START ===', $data);

            // === GUDANG TUJUAN: Gudang Pembahanan ===
            $targetWarehouse = Warehouse::where('code', 'BUFFER')->first();
            if (!$targetWarehouse) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Pembahanan (BUFFER) tidak ditemukan di master.'],
                ]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';

            // === NOMOR DOKUMEN ===
            $runningNumber  = PembahananProduction::whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->count() + 1;
            $documentNumber = 'PBH-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            // === SIMPAN HEADER ===
            $pembahanan = PembahananProduction::create([
                'document_number'       => $documentNumber,
                'date'                  => $data['date'],
                'estimated_finish_date' => $data['estimated_finish_date'] ?? null,
                'ref_po_id'             => $data['ref_po_id'],
                'source_warehouse_id'   => $data['source_warehouse_id'],
                'notes'                 => $data['notes'] ?? null,
                'created_by'            => Auth::id(),
            ]);

            // === PROSES SETIAP ITEM ===
            foreach ($data['items'] as $index => $itemData) {
                $itemId = $itemData['item_id'];
                $qty    = $itemData['qty'];

                $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                // Cek stok di gudang sumber
                $sourceInventory = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $data['source_warehouse_id'])
                    ->lockForUpdate()
                    ->first();

                $availableQty = $sourceInventory?->qty_pcs ?? 0;

                if ($availableQty < $qty) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty" => [
                            "'{$itemName}' stok tidak cukup. Tersedia: {$availableQty} pcs, dibutuhkan: {$qty} pcs."
                        ],
                    ]);
                }

                // Kurangi stok sumber (OUT)
                $sourceInventory->decrement('qty_pcs', $qty);

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $data['source_warehouse_id'],
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'OUT',
                    'transaction_type' => 'PEMBAHANAN',
                    'reference_type'   => 'PembahananProduction',
                    'reference_id'     => $pembahanan->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Ambil untuk pembahanan PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                // Tambah stok di Gudang Pembahanan (IN)
                $targetInventory = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $targetWarehouse->id)
                    ->lockForUpdate()
                    ->first();

                if ($targetInventory) {
                    $targetInventory->increment('qty_pcs', $qty);
                } else {
                    Inventory::create([
                        'item_id'      => $itemId,
                        'warehouse_id' => $targetWarehouse->id,
                        'qty_pcs'      => $qty,
                        'ref_po_id'    => $data['ref_po_id'],
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $targetWarehouse->id,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'IN',
                    'transaction_type' => 'PEMBAHANAN',
                    'reference_type'   => 'PembahananProduction',
                    'reference_id'     => $pembahanan->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Masuk Gudang Pembahanan untuk PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                // Simpan detail item
                PembahananProductionItem::create([
                    'pembahanan_production_id' => $pembahanan->id,
                    'item_id'                  => $itemId,
                    'qty'                      => $qty,
                ]);

                Log::info("Item #{$index} processed: {$itemName} - {$qty} pcs");
            }

            // === UPDATE STAGE PO ===
            if ($productionOrder) {
                $productionOrder->current_stage = 'pembahanan';
                $productionOrder->status        = 'in_progress';
                $productionOrder->save();
            }

            Log::info('=== PEMBAHANAN END ===', ['doc' => $documentNumber]);

            return response()->json([
                'success' => true,
                'message' => "Proses Pembahanan berhasil ({$documentNumber})",
                'data'    => [
                    'id'                    => $pembahanan->id,
                    'document_number'       => $documentNumber,
                    'estimated_finish_date' => $pembahanan->estimated_finish_date,
                    'total_items'           => count($data['items']),
                ],
            ], 201);
        });
    }
}