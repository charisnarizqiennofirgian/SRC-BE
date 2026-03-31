<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssemblingProduction;
use App\Models\AssemblingProductionInput;
use App\Models\AssemblingProductionOutput;
use App\Models\AssemblingProductionReject;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AssemblingProductionController extends Controller
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
    // GET: Stok dari gudang sumber
    // Bisa dari MESIN, RUSKOMP, ASSEMBLING
    // =============================================
    public function getSourceItems(Request $request)
    {
        $warehouseCodes = ['MESIN', 'RUSKOMP', 'ASSEMBLING'];

        $result = [];

        foreach ($warehouseCodes as $code) {
            $warehouse = Warehouse::where('code', $code)->first();
            if (!$warehouse) continue;

            $inventories = Inventory::where('warehouse_id', $warehouse->id)
                ->where('qty_pcs', '>', 0)
                ->with('item')
                ->get()
                ->map(function ($inv) use ($warehouse) {
                    return [
                        'item_id'        => $inv->item_id,
                        'item_code'      => $inv->item?->code ?? '-',
                        'item_name'      => $inv->item?->name ?? '-',
                        'qty_available'  => (float) $inv->qty_pcs,
                        'warehouse_id'   => $warehouse->id,
                        'warehouse_code' => $warehouse->code,
                        'warehouse_name' => $warehouse->name,
                        'label'          => "[{$warehouse->code}] {$inv->item?->code} - {$inv->item?->name}",
                    ];
                });

            $result = array_merge($result, $inventories->toArray());
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    // =============================================
    // POST: Simpan proses assembling
    // =============================================
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'         => ['required', 'date'],
            'process_type' => ['required', 'in:sub_assembling,rakit'],
            'ref_po_id'    => ['required', 'integer', 'exists:production_orders,id'],
            'notes'        => ['nullable', 'string'],

            'inputs'                 => ['required', 'array', 'min:1'],
            'inputs.*.item_id'       => ['required', 'integer', 'exists:items,id'],
            'inputs.*.warehouse_id'  => ['required', 'integer', 'exists:warehouses,id'],
            'inputs.*.qty'           => ['required', 'numeric', 'min:0.01'],

            'outputs'           => ['required', 'array', 'min:1'],
            'outputs.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'outputs.*.qty'     => ['required', 'numeric', 'min:1'],

            'rejects'              => ['nullable', 'array'],
            'rejects.*.item_id'    => ['required_with:rejects', 'integer', 'exists:items,id'],
            'rejects.*.qty'        => ['required_with:rejects', 'numeric', 'min:0.01'],
            'rejects.*.keterangan' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data) {
            $processLabel = $data['process_type'] === 'sub_assembling'
                ? 'Sub Assembling' : 'Rakit';

            Log::info("=== {$processLabel} START ===", ['po_id' => $data['ref_po_id']]);

            // === GUDANG ===
            $warehouseAssembling = Warehouse::where('code', 'ASSEMBLING')->first();
            $warehouseReject     = Warehouse::where('code', 'REJECT')->first();

            if (!$warehouseAssembling) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Assembling tidak ditemukan.'],
                ]);
            }
            if (!$warehouseReject) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Reject tidak ditemukan.'],
                ]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';

            // === NOMOR DOKUMEN ===
            $prefix = $data['process_type'] === 'sub_assembling' ? 'SUB' : 'RKT';
            $runningNumber  = AssemblingProduction::whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->where('process_type', $data['process_type'])
                ->count() + 1;
            $documentNumber = "{$prefix}-" . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            // === SIMPAN HEADER ===
            $assembling = AssemblingProduction::create([
                'document_number' => $documentNumber,
                'date'            => $data['date'],
                'process_type'    => $data['process_type'],
                'ref_po_id'       => $data['ref_po_id'],
                'notes'           => $data['notes'] ?? null,
                'created_by'      => Auth::id(),
            ]);

            // === INPUT: Kurangi stok gudang sumber ===
            foreach ($data['inputs'] as $index => $input) {
                $itemId      = $input['item_id'];
                $warehouseId = $input['warehouse_id'];
                $qty         = $input['qty'];
                $itemName    = Item::find($itemId)?->name ?? "ID {$itemId}";
                $whName      = Warehouse::find($warehouseId)?->name ?? "ID {$warehouseId}";

                $sourceInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first();

                $availableQty = $sourceInv?->qty_pcs ?? 0;

                if ($availableQty < $qty) {
                    throw ValidationException::withMessages([
                        "inputs.{$index}.qty" => [
                            "'{$itemName}' stok di {$whName} tidak cukup. Tersedia: {$availableQty} pcs, dibutuhkan: {$qty} pcs."
                        ],
                    ]);
                }

                $sourceInv->decrement('qty_pcs', $qty);

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $warehouseId,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'OUT',
                    'transaction_type' => strtoupper($data['process_type']),
                    'reference_type'   => 'AssemblingProduction',
                    'reference_id'     => $assembling->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Masuk {$processLabel} ({$documentNumber}) - PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                AssemblingProductionInput::create([
                    'assembling_production_id' => $assembling->id,
                    'item_id'                  => $itemId,
                    'warehouse_id'             => $warehouseId,
                    'qty'                      => $qty,
                ]);

                Log::info("Input: {$itemName} - {$qty} pcs dari {$whName}");
            }

            // === OUTPUT: Tambah stok Gudang Assembling ===
            foreach ($data['outputs'] as $output) {
                $itemId   = $output['item_id'];
                $qty      = $output['qty'];
                $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                $targetInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $warehouseAssembling->id)
                    ->lockForUpdate()
                    ->first();

                if ($targetInv) {
                    $targetInv->increment('qty_pcs', $qty);
                } else {
                    Inventory::create([
                        'item_id'      => $itemId,
                        'warehouse_id' => $warehouseAssembling->id,
                        'qty_pcs'      => $qty,
                        'ref_po_id'    => $data['ref_po_id'],
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $warehouseAssembling->id,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'IN',
                    'transaction_type' => strtoupper($data['process_type']),
                    'reference_type'   => 'AssemblingProduction',
                    'reference_id'     => $assembling->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Hasil {$processLabel} masuk Gudang Assembling ({$documentNumber}) - PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                AssemblingProductionOutput::create([
                    'assembling_production_id' => $assembling->id,
                    'item_id'                  => $itemId,
                    'qty'                      => $qty,
                ]);

                Log::info("Output: {$itemName} - {$qty} pcs ke Gudang Assembling");
            }

            // === REJECT → Gudang Reject ===
            if (!empty($data['rejects'])) {
                foreach ($data['rejects'] as $reject) {
                    if (empty($reject['item_id']) || empty($reject['qty'])) continue;

                    $itemId   = $reject['item_id'];
                    $qty      = $reject['qty'];
                    $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

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
                        'transaction_type' => 'REJECT',
                        'reference_type'   => 'AssemblingProduction',
                        'reference_id'     => $assembling->id,
                        'reference_number' => $documentNumber,
                        'notes'            => "Reject {$processLabel} - {$reject['keterangan']} ({$documentNumber})",
                        'user_id'          => Auth::id(),
                    ]);

                    AssemblingProductionReject::create([
                        'assembling_production_id' => $assembling->id,
                        'item_id'                  => $itemId,
                        'qty'                      => $qty,
                        'keterangan'               => $reject['keterangan'] ?? null,
                    ]);

                    Log::info("Reject: {$itemName} - {$qty} pcs");
                }
            }

            // === UPDATE STAGE PO ===
            if ($productionOrder) {
                $productionOrder->current_stage = 'assembly';
                $productionOrder->status        = 'in_progress';
                $productionOrder->save();
            }

            Log::info("=== {$processLabel} END ===", ['doc' => $documentNumber]);

            return response()->json([
                'success' => true,
                'message' => "{$processLabel} berhasil dicatat ({$documentNumber})",
                'data'    => [
                    'id'              => $assembling->id,
                    'document_number' => $documentNumber,
                    'process_type'    => $data['process_type'],
                    'total_inputs'    => count($data['inputs']),
                    'total_outputs'   => count($data['outputs']),
                    'total_rejects'   => count($data['rejects'] ?? []),
                ],
            ], 201);
        });
    }
}