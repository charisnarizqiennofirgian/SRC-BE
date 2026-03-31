<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\RustikKomponenProduction;
use App\Models\RustikKomponenInput;
use App\Models\RustikKomponenOutput;
use App\Models\RustikKomponenReject;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RustikKomponenController extends Controller
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
    // GET: Stok komponen di Gudang Mesin
    // =============================================
    public function getMesinItems()
    {
        $warehouseMesin = Warehouse::where('code', 'MESIN')->first();

        if (!$warehouseMesin) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $inventories = Inventory::where('warehouse_id', $warehouseMesin->id)
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
    // POST: Simpan proses rustik komponen
    // =============================================
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'      => ['required', 'date'],
            'ref_po_id' => ['required', 'integer', 'exists:production_orders,id'],
            'notes'     => ['nullable', 'string'],

            'inputs'           => ['required', 'array', 'min:1'],
            'inputs.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'inputs.*.qty'     => ['required', 'numeric', 'min:0.01'],

            'outputs'           => ['required', 'array', 'min:1'],
            'outputs.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'outputs.*.qty'     => ['required', 'numeric', 'min:1'],

            'rejects'                => ['nullable', 'array'],
            'rejects.*.item_id'      => ['required_with:rejects', 'integer', 'exists:items,id'],
            'rejects.*.qty'          => ['required_with:rejects', 'numeric', 'min:0.01'],
            'rejects.*.keterangan'   => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data) {
            Log::info('=== RUSTIK KOMPONEN START ===', ['po_id' => $data['ref_po_id']]);

            // === GUDANG ===
            $warehouseMesin      = Warehouse::where('code', 'MESIN')->first();
            $warehouseRuskomp    = Warehouse::where('code', 'RUSKOMP')->first();
            $warehouseReject     = Warehouse::where('code', 'REJECT')->first();

            if (!$warehouseMesin) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Mesin tidak ditemukan.'],
                ]);
            }
            if (!$warehouseRuskomp) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Rustik Komponen (RUSKOMP) tidak ditemukan.'],
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
            $runningNumber  = RustikKomponenProduction::whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->count() + 1;
            $documentNumber = 'RKP-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            // === SIMPAN HEADER ===
            $production = RustikKomponenProduction::create([
                'document_number' => $documentNumber,
                'date'            => $data['date'],
                'ref_po_id'       => $data['ref_po_id'],
                'notes'           => $data['notes'] ?? null,
                'created_by'      => Auth::id(),
            ]);

            // === INPUT: Kurangi stok Gudang Mesin ===
            foreach ($data['inputs'] as $index => $input) {
                $itemId   = $input['item_id'];
                $qty      = $input['qty'];
                $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                $sourceInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $warehouseMesin->id)
                    ->lockForUpdate()
                    ->first();

                $availableQty = $sourceInv?->qty_pcs ?? 0;

                if ($availableQty < $qty) {
                    throw ValidationException::withMessages([
                        "inputs.{$index}.qty" => [
                            "'{$itemName}' stok di Gudang Mesin tidak cukup. Tersedia: {$availableQty} pcs, dibutuhkan: {$qty} pcs."
                        ],
                    ]);
                }

                $sourceInv->decrement('qty_pcs', $qty);

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $warehouseMesin->id,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'OUT',
                    'transaction_type' => 'RUSTIK_KOMPONEN',
                    'reference_type'   => 'RustikKomponenProduction',
                    'reference_id'     => $production->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Masuk Rustik Komponen ({$documentNumber}) - PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                RustikKomponenInput::create([
                    'rustik_komponen_production_id' => $production->id,
                    'item_id'                       => $itemId,
                    'qty'                           => $qty,
                ]);

                Log::info("Input: {$itemName} - {$qty} pcs dari Gudang Mesin");
            }

            // === OUTPUT: Tambah stok Gudang Rustik Komponen ===
            foreach ($data['outputs'] as $output) {
                $itemId   = $output['item_id'];
                $qty      = $output['qty'];
                $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                $targetInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $warehouseRuskomp->id)
                    ->lockForUpdate()
                    ->first();

                if ($targetInv) {
                    $targetInv->increment('qty_pcs', $qty);
                } else {
                    Inventory::create([
                        'item_id'      => $itemId,
                        'warehouse_id' => $warehouseRuskomp->id,
                        'qty_pcs'      => $qty,
                        'ref_po_id'    => $data['ref_po_id'],
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $warehouseRuskomp->id,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'IN',
                    'transaction_type' => 'RUSTIK_KOMPONEN',
                    'reference_type'   => 'RustikKomponenProduction',
                    'reference_id'     => $production->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Hasil Rustik Komponen masuk gudang ({$documentNumber}) - PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                RustikKomponenOutput::create([
                    'rustik_komponen_production_id' => $production->id,
                    'item_id'                       => $itemId,
                    'qty'                           => $qty,
                ]);

                Log::info("Output: {$itemName} - {$qty} pcs ke Gudang Rustik Komponen");
            }

            // === REJECT: Tambah stok Gudang Reject ===
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
                        'reference_type'   => 'RustikKomponenProduction',
                        'reference_id'     => $production->id,
                        'reference_number' => $documentNumber,
                        'notes'            => "Reject Rustik Komponen - {$reject['keterangan']} ({$documentNumber})",
                        'user_id'          => Auth::id(),
                    ]);

                    RustikKomponenReject::create([
                        'rustik_komponen_production_id' => $production->id,
                        'item_id'                       => $itemId,
                        'qty'                           => $qty,
                        'keterangan'                    => $reject['keterangan'] ?? null,
                    ]);

                    Log::info("Reject: {$itemName} - {$qty} pcs");
                }
            }

            // === UPDATE STAGE PO ===
            if ($productionOrder) {
                $productionOrder->current_stage = 'rustik_komponen';
                $productionOrder->status        = 'in_progress';
                $productionOrder->save();
            }

            Log::info('=== RUSTIK KOMPONEN END ===', ['doc' => $documentNumber]);

            return response()->json([
                'success' => true,
                'message' => "Proses Rustik Komponen berhasil ({$documentNumber})",
                'data'    => [
                    'id'              => $production->id,
                    'document_number' => $documentNumber,
                    'total_inputs'    => count($data['inputs']),
                    'total_outputs'   => count($data['outputs']),
                    'total_rejects'   => count($data['rejects'] ?? []),
                ],
            ], 201);
        });
    }
}