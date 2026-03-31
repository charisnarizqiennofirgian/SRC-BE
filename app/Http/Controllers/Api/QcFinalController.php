<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\QcFinalProduction;
use App\Models\QcFinalPassedItem;
use App\Models\QcFinalRejectItem;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class QcFinalController extends Controller
{
    // =============================================
    // GET: Available POs
    // =============================================
    public function getAvailablePos()
    {
        $pos = ProductionOrder::where('status', '!=', 'completed')
            ->with(['salesOrder.buyer'])
            ->orderByDesc('created_at')
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
    // GET: Stok dari gudang sumber (fleksibel)
    // =============================================
    public function getSourceItems(Request $request)
    {
        $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
        ]);

        $inventories = Inventory::where('warehouse_id', $request->warehouse_id)
            ->where('qty_pcs', '>', 0)
            ->with('item')
            ->get()
            ->map(function ($inv) {
                return [
                    'item_id'       => $inv->item_id,
                    'item_code'     => $inv->item?->code ?? '-',
                    'item_name'     => $inv->item?->name ?? '-',
                    'qty_available' => (float) $inv->qty_pcs,
                ];
            });

        return response()->json(['success' => true, 'data' => $inventories]);
    }

    // =============================================
    // POST: Simpan QC Final
    // =============================================
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'                => ['required', 'date'],
            'ref_po_id'           => ['required', 'integer', 'exists:production_orders,id'],
            'source_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'notes'               => ['nullable', 'string'],

            // Item yang lolos QC
            'passed'           => ['required', 'array', 'min:1'],
            'passed.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'passed.*.qty'     => ['required', 'numeric', 'min:1'],

            // Item yang reject (opsional)
            'rejects'                => ['nullable', 'array'],
            'rejects.*.item_id'      => ['required_with:rejects', 'integer', 'exists:items,id'],
            'rejects.*.qty'          => ['required_with:rejects', 'numeric', 'min:1'],
            'rejects.*.keterangan'   => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data) {
            Log::info('=== QC FINAL START ===', ['po_id' => $data['ref_po_id']]);

            // === GUDANG ===
            $warehousePacking = Warehouse::where('code', 'PACKING')->first();
            $warehouseReject  = Warehouse::where('code', 'REJECT')->first();
            $sourceWarehouse  = Warehouse::find($data['source_warehouse_id']);

            if (!$warehousePacking) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Packing tidak ditemukan.'],
                ]);
            }
            if (!$warehouseReject) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Reject tidak ditemukan.'],
                ]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';
            $sourceName      = $sourceWarehouse?->name ?? '-';

            // === NOMOR DOKUMEN ===
            $runningNumber  = QcFinalProduction::whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->count() + 1;
            $documentNumber = 'QCF-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            // === SIMPAN HEADER ===
            $qcFinal = QcFinalProduction::create([
                'document_number'     => $documentNumber,
                'date'                => $data['date'],
                'ref_po_id'           => $data['ref_po_id'],
                'source_warehouse_id' => $data['source_warehouse_id'],
                'notes'               => $data['notes'] ?? null,
                'created_by'          => Auth::id(),
            ]);

            // === PROSES ITEM LOLOS QC ===
            // Kurangi stok gudang sumber → tambah stok Gudang Packing
            foreach ($data['passed'] as $index => $passed) {
                $itemId   = $passed['item_id'];
                $qty      = $passed['qty'];
                $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                // Cek & kurangi stok sumber
                $sourceInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $data['source_warehouse_id'])
                    ->lockForUpdate()
                    ->first();

                $availableQty = $sourceInv?->qty_pcs ?? 0;

                if ($availableQty < $qty) {
                    throw ValidationException::withMessages([
                        "passed.{$index}.qty" => [
                            "'{$itemName}' stok di {$sourceName} tidak cukup. Tersedia: {$availableQty} pcs, dibutuhkan: {$qty} pcs."
                        ],
                    ]);
                }

                $sourceInv->decrement('qty_pcs', $qty);

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $data['source_warehouse_id'],
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'OUT',
                    'transaction_type' => 'QC_FINAL',
                    'reference_type'   => 'QcFinalProduction',
                    'reference_id'     => $qcFinal->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Lolos QC Final dari {$sourceName} ({$documentNumber}) - PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                // Tambah stok Gudang Packing
                $packingInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $warehousePacking->id)
                    ->lockForUpdate()
                    ->first();

                if ($packingInv) {
                    $packingInv->increment('qty_pcs', $qty);
                } else {
                    Inventory::create([
                        'item_id'      => $itemId,
                        'warehouse_id' => $warehousePacking->id,
                        'qty_pcs'      => $qty,
                        'ref_po_id'    => $data['ref_po_id'],
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $warehousePacking->id,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'IN',
                    'transaction_type' => 'QC_FINAL',
                    'reference_type'   => 'QcFinalProduction',
                    'reference_id'     => $qcFinal->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Lolos QC Final masuk Gudang Packing ({$documentNumber}) - PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                QcFinalPassedItem::create([
                    'qc_final_production_id' => $qcFinal->id,
                    'item_id'                => $itemId,
                    'qty'                    => $qty,
                ]);

                Log::info("Passed QC: {$itemName} - {$qty} pcs → Gudang Packing");
            }

            // === PROSES ITEM REJECT ===
            // Kurangi stok sumber → tambah stok Gudang Reject
            if (!empty($data['rejects'])) {
                foreach ($data['rejects'] as $reject) {
                    if (empty($reject['item_id']) || empty($reject['qty'])) continue;

                    $itemId   = $reject['item_id'];
                    $qty      = $reject['qty'];
                    $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                    // Cek & kurangi stok sumber
                    $sourceInv = Inventory::where('item_id', $itemId)
                        ->where('warehouse_id', $data['source_warehouse_id'])
                        ->lockForUpdate()
                        ->first();

                    $availableQty = $sourceInv?->qty_pcs ?? 0;

                    if ($availableQty < $qty) {
                        throw ValidationException::withMessages([
                            'rejects' => [
                                "Reject '{$itemName}' stok di {$sourceName} tidak cukup. Tersedia: {$availableQty} pcs."
                            ],
                        ]);
                    }

                    $sourceInv->decrement('qty_pcs', $qty);

                    InventoryLog::create([
                        'date'             => $data['date'],
                        'time'             => now()->toTimeString(),
                        'item_id'          => $itemId,
                        'warehouse_id'     => $data['source_warehouse_id'],
                        'qty'              => $qty,
                        'qty_m3'           => 0,
                        'direction'        => 'OUT',
                        'transaction_type' => 'QC_REJECT',
                        'reference_type'   => 'QcFinalProduction',
                        'reference_id'     => $qcFinal->id,
                        'reference_number' => $documentNumber,
                        'notes'            => "Reject QC Final - {$reject['keterangan']} ({$documentNumber})",
                        'user_id'          => Auth::id(),
                    ]);

                    // Tambah stok Gudang Reject
                    $rejectInv = Inventory::where('item_id', $itemId)
                        ->where('warehouse_id', $warehouseReject->id)
                        ->lockForUpdate()
                        ->first();

                    if ($rejectInv) {
                        $rejectInv->increment('qty_pcs', $qty);
                    } else {
                        Inventory::create([
                            'item_id'      => $itemId,
                            'warehouse_id' => $warehouseReject->id,
                            'qty_pcs'      => $qty,
                        ]);
                    }

                    InventoryLog::create([
                        'date'             => $data['date'],
                        'time'             => now()->toTimeString(),
                        'item_id'          => $itemId,
                        'warehouse_id'     => $warehouseReject->id,
                        'qty'              => $qty,
                        'qty_m3'           => 0,
                        'direction'        => 'IN',
                        'transaction_type' => 'QC_REJECT',
                        'reference_type'   => 'QcFinalProduction',
                        'reference_id'     => $qcFinal->id,
                        'reference_number' => $documentNumber,
                        'notes'            => "Reject QC Final masuk Gudang Reject - {$reject['keterangan']} ({$documentNumber})",
                        'user_id'          => Auth::id(),
                    ]);

                    QcFinalRejectItem::create([
                        'qc_final_production_id' => $qcFinal->id,
                        'item_id'                => $itemId,
                        'qty'                    => $qty,
                        'keterangan'             => $reject['keterangan'] ?? null,
                    ]);

                    Log::info("Reject QC: {$itemName} - {$qty} pcs → Gudang Reject");
                }
            }

            // === UPDATE STAGE PO ===
            if ($productionOrder) {
                $productionOrder->current_stage = 'qc_final';
                $productionOrder->status        = 'in_progress';
                $productionOrder->save();
            }

            Log::info('=== QC FINAL END ===', ['doc' => $documentNumber]);

            return response()->json([
                'success' => true,
                'message' => "QC Final berhasil dicatat ({$documentNumber})",
                'data'    => [
                    'id'              => $qcFinal->id,
                    'document_number' => $documentNumber,
                    'total_passed'    => count($data['passed']),
                    'total_rejects'   => count($data['rejects'] ?? []),
                ],
            ], 201);
        });
    }
}