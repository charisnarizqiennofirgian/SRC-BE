<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\Machine;
use App\Models\MesinProduction;
use App\Models\MesinProductionInput;
use App\Models\MesinProductionOutput;
use App\Models\MesinProductionReject;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use App\Services\ProductionOrderProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OperatorMesinController extends Controller
{
    protected ProductionOrderProgressService $poProgress;

    public function __construct(ProductionOrderProgressService $poProgress)
    {
        $this->poProgress = $poProgress;
    }

    // =============================================
    // GET: Daftar semua mesin aktif
    // =============================================
    public function getMachines()
    {
        $machines = Machine::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'description']);

        return response()->json(['success' => true, 'data' => $machines]);
    }

    // =============================================
    // GET: Available POs untuk dropdown
    // =============================================
    public function getAvailablePos()
    {
        $pos = ProductionOrder::whereIn('current_stage', [
                'moulding', 'mesin', 'pembahanan', 'sawmill', 'pending'
            ])
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
    // GET: Stok komponen di Gudang S4S
    // =============================================
    public function getS4sItems(Request $request)
    {
        $warehouseS4S = Warehouse::where('code', 'S4S')->first();

        if (!$warehouseS4S) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $inventories = Inventory::where('warehouse_id', $warehouseS4S->id)
            ->where('qty_pcs', '>', 0)
            ->with('item')
            ->get()
            ->map(function ($inv) {
                return [
                    'id'            => $inv->id,
                    'item_id'       => $inv->item_id,
                    'item_code'     => $inv->item?->code ?? '-',
                    'item_name'     => $inv->item?->name ?? '-',
                    'qty_available' => (float) $inv->qty_pcs,
                ];
            });

        return response()->json(['success' => true, 'data' => $inventories]);
    }

    // =============================================
    // POST: Simpan proses mesin
    // =============================================
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'       => ['required', 'date'],
            'ref_po_id'  => ['required', 'integer', 'exists:production_orders,id'],
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'notes'      => ['nullable', 'string'],

            // Input: komponen dari Gudang S4S
            'inputs'           => ['required', 'array', 'min:1'],
            'inputs.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'inputs.*.qty'     => ['required', 'numeric', 'min:0.01'],

            // Output: komponen hasil → Gudang Mesin
            'outputs'           => ['required', 'array', 'min:1'],
            'outputs.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'outputs.*.qty'     => ['required', 'numeric', 'min:1'],

            // Reject: opsional
            'rejects'                => ['nullable', 'array'],
            'rejects.*.item_id'      => ['required_with:rejects', 'integer', 'exists:items,id'],
            'rejects.*.qty'          => ['required_with:rejects', 'numeric', 'min:0.01'],
            'rejects.*.keterangan'   => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data) {
            Log::info('=== MESIN PRODUCTION START ===', [
                'po_id'      => $data['ref_po_id'],
                'machine_id' => $data['machine_id'],
            ]);

            // === GUDANG ===
            $warehouseS4S    = Warehouse::where('code', 'S4S')->first();
            $warehouseMesin  = Warehouse::where('code', 'MESIN')->first();
            $warehouseReject = Warehouse::where('code', 'REJECT')->first();

            if (!$warehouseS4S) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang S4S tidak ditemukan di master.'],
                ]);
            }
            if (!$warehouseMesin) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Mesin tidak ditemukan di master.'],
                ]);
            }
            if (!$warehouseReject) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Reject tidak ditemukan di master.'],
                ]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';
            $machine         = Machine::find($data['machine_id']);
            $machineName     = $machine?->name ?? '-';

            // === NOMOR DOKUMEN ===
            $runningNumber  = MesinProduction::whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->count() + 1;
            $documentNumber = 'MSN-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            // === SIMPAN HEADER ===
            $mesinProduction = MesinProduction::create([
                'document_number' => $documentNumber,
                'date'            => $data['date'],
                'ref_po_id'       => $data['ref_po_id'],
                'machine_id'      => $data['machine_id'],
                'notes'           => $data['notes'] ?? null,
                'created_by'      => Auth::id(),
            ]);

            // === PROSES INPUT (Komponen dari Gudang S4S) ===
            foreach ($data['inputs'] as $index => $input) {
                $itemId   = $input['item_id'];
                $qty      = $input['qty'];
                $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                // Cek & kurangi stok di Gudang S4S
                $sourceInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $warehouseS4S->id)
                    ->lockForUpdate()
                    ->first();

                $availableQty = $sourceInv?->qty_pcs ?? 0;

                if ($availableQty < $qty) {
                    throw ValidationException::withMessages([
                        "inputs.{$index}.qty" => [
                            "'{$itemName}' stok di Gudang S4S tidak cukup. Tersedia: {$availableQty} pcs, dibutuhkan: {$qty} pcs."
                        ],
                    ]);
                }

                $sourceInv->decrement('qty_pcs', $qty);

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $warehouseS4S->id,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'OUT',
                    'transaction_type' => 'MESIN',
                    'reference_type'   => 'MesinProduction',
                    'reference_id'     => $mesinProduction->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Masuk mesin {$machineName} ({$documentNumber}) - PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                MesinProductionInput::create([
                    'mesin_production_id' => $mesinProduction->id,
                    'item_id'             => $itemId,
                    'qty'                 => $qty,
                ]);

                Log::info("Input: {$itemName} - {$qty} pcs dari S4S");
            }

            // === PROSES OUTPUT (Komponen → Gudang Mesin) ===
            foreach ($data['outputs'] as $output) {
                $itemId   = $output['item_id'];
                $qty      = $output['qty'];
                $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                $targetInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $warehouseMesin->id)
                    ->lockForUpdate()
                    ->first();

                if ($targetInv) {
                    $targetInv->increment('qty_pcs', $qty);
                } else {
                    Inventory::create([
                        'item_id'      => $itemId,
                        'warehouse_id' => $warehouseMesin->id,
                        'qty_pcs'      => $qty,
                        'ref_po_id'    => $data['ref_po_id'],
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $warehouseMesin->id,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'IN',
                    'transaction_type' => 'MESIN',
                    'reference_type'   => 'MesinProduction',
                    'reference_id'     => $mesinProduction->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Hasil mesin {$machineName} masuk Gudang Mesin ({$documentNumber}) - PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                MesinProductionOutput::create([
                    'mesin_production_id' => $mesinProduction->id,
                    'item_id'             => $itemId,
                    'qty'                 => $qty,
                ]);

                Log::info("Output: {$itemName} - {$qty} pcs ke Gudang Mesin");
            }

            // === PROSES REJECT → Gudang Reject ===
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
                        'reference_type'   => 'MesinProduction',
                        'reference_id'     => $mesinProduction->id,
                        'reference_number' => $documentNumber,
                        'notes'            => "Reject di mesin {$machineName} - {$reject['keterangan']} ({$documentNumber})",
                        'user_id'          => Auth::id(),
                    ]);

                    MesinProductionReject::create([
                        'mesin_production_id' => $mesinProduction->id,
                        'item_id'             => $itemId,
                        'qty'                 => $qty,
                        'machine_id'          => $data['machine_id'],
                        'keterangan'          => $reject['keterangan'] ?? null,
                    ]);

                    Log::info("Reject: {$itemName} - {$qty} pcs di mesin {$machineName}");
                }
            }

            // === UPDATE STAGE PO ===
            if ($productionOrder) {
                $productionOrder->current_stage = 'mesin';
                $productionOrder->status        = 'in_progress';
                $productionOrder->save();
            }

            Log::info('=== MESIN PRODUCTION END ===', ['doc' => $documentNumber]);

            return response()->json([
                'success' => true,
                'message' => "Proses Mesin ({$machineName}) berhasil dicatat ({$documentNumber})",
                'data'    => [
                    'id'              => $mesinProduction->id,
                    'document_number' => $documentNumber,
                    'machine'         => $machineName,
                    'total_inputs'    => count($data['inputs']),
                    'total_outputs'   => count($data['outputs']),
                    'total_rejects'   => count($data['rejects'] ?? []),
                ],
            ], 201);
        });
    }

    // =============================================
    // POST: Tandai PO selesai proses mesin
    // =============================================
    public function tandaiSelesai(Request $request, $poId)
    {
        $productionOrder = ProductionOrder::findOrFail($poId);

        $productionOrder->update([
            'current_stage' => 'assembly',
            'status'        => 'in_progress',
        ]);

        return response()->json([
            'success' => true,
            'message' => "PO {$productionOrder->po_number} selesai proses Mesin, lanjut ke Assembling.",
        ]);
    }
}