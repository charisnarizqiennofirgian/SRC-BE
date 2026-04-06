<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SawmillProduction;
use App\Models\SawmillProductionLog;
use App\Models\SawmillProductionRst;
use App\Models\Item;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use App\Services\ProductionOrderProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SawmillProductionController extends Controller
{
    protected ProductionOrderProgressService $poProgress;

    public function __construct(ProductionOrderProgressService $poProgress)
    {
        $this->poProgress = $poProgress;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date'                   => ['required', 'date'],
            'estimated_finish_date'  => ['nullable', 'date', 'after_or_equal:date'],
            'warehouse_from_id'      => ['required', 'exists:warehouses,id'],
            'warehouse_to_id'        => ['required', 'exists:warehouses,id'],
            'notes'                  => ['nullable', 'string'],
            'ref_po_id'              => ['nullable', 'integer', 'exists:production_orders,id'],
            'ref_product_id'         => ['nullable', 'integer'],

            'logs'                   => ['required', 'array', 'min:1'],
            'logs.*.item_log_id'     => ['required', 'exists:items,id'],
            'logs.*.qty_log_pcs'     => ['required', 'integer', 'min:1'],

            'rsts'                   => ['required', 'array', 'min:1'],
            'rsts.*.item_rst_id'     => ['required', 'exists:items,id'],
            'rsts.*.qty_rst_pcs'     => ['required', 'integer', 'min:1'],
            'rsts.*.volume_rst_m3'      => ['required', 'numeric', 'min:0'],

            // Jeblosan → Gudang SAWMILL
            'jeblosans'                 => ['required', 'array', 'min:1'],
            'jeblosans.*.item_id'       => ['required', 'exists:items,id'],
            'jeblosans.*.qty_pcs'       => ['required', 'integer', 'min:1'],
            'jeblosans.*.volume_m3'     => ['nullable', 'numeric', 'min:0'],
            'jeblosans.*.is_sisa'       => ['nullable', 'boolean'],
        ]);

        $production = DB::transaction(function () use ($data) {

            $runningNumber  = SawmillProduction::whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->count() + 1;
            $documentNumber = 'SW-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            $productionOrder = !empty($data['ref_po_id'])
                ? ProductionOrder::find($data['ref_po_id'])
                : null;

            $referenceNumber = $productionOrder?->po_number ?? $documentNumber;
            $referenceId     = $productionOrder?->id ?? null;

            $production = SawmillProduction::create([
                'document_number'        => $documentNumber,
                'date'                   => $data['date'],
                'estimated_finish_date'  => $data['estimated_finish_date'] ?? null,
                'warehouse_from_id'      => $data['warehouse_from_id'],
                'warehouse_to_id'        => $data['warehouse_to_id'],
                'notes'                  => $data['notes'] ?? null,
                'ref_po_id'              => $referenceId,
                'ref_product_id'         => $data['ref_product_id'] ?? null,
            ]);

            foreach ($data['logs'] as $log) {
                $inventory = Inventory::where('item_id', $log['item_log_id'])
                    ->where('warehouse_id', $data['warehouse_from_id'])
                    ->lockForUpdate()
                    ->first();

                $currentQty = $inventory?->qty_pcs ?? 0;

                if ($currentQty < $log['qty_log_pcs']) {
                    $itemName = Item::find($log['item_log_id'])?->name ?? "ID {$log['item_log_id']}";
                    throw ValidationException::withMessages([
                        'logs' => ["Stok log '{$itemName}' tidak mencukupi. Tersedia: {$currentQty} pcs, dibutuhkan: {$log['qty_log_pcs']} pcs."],
                    ]);
                }

                $inventory->decrement('qty_pcs', $log['qty_log_pcs']);

                $itemLog        = Item::find($log['item_log_id']);
                $volumePerPcs   = $itemLog?->volume_m3 ?? 0;
                $volumeLogTotal = $log['qty_log_pcs'] * $volumePerPcs;

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $log['item_log_id'],
                    'warehouse_id'     => $data['warehouse_from_id'],
                    'qty'              => $log['qty_log_pcs'],
                    'qty_m3'           => $volumeLogTotal,
                    'direction'        => 'OUT',
                    'transaction_type' => 'SAWMILL',
                    'reference_type'   => $referenceId ? 'ProductionOrder' : 'SawmillProduction',
                    'reference_id'     => $referenceId ?? $production->id,
                    'reference_number' => $referenceNumber,
                    'notes'            => "Bahan log untuk produksi sawmill ({$documentNumber})",
                    'user_id'          => Auth::id(),
                ]);

                SawmillProductionLog::create([
                    'sawmill_production_id' => $production->id,
                    'item_log_id'           => $log['item_log_id'],
                    'qty_log_pcs'           => $log['qty_log_pcs'],
                    'volume_log_m3'         => $volumeLogTotal,
                ]);
            }

            // === OUTPUT 1: JEBLOSAN → GUDANG SAWMILL ===
            $warehouseSawmill = Warehouse::where('code', 'SAWMILL')->firstOrFail();

            foreach ($data['jeblosans'] as $jeblosan) {
                $isSisa = $jeblosan['is_sisa'] ?? false;

                $inv = Inventory::where('item_id', $jeblosan['item_id'])
                    ->where('warehouse_id', $warehouseSawmill->id)
                    ->lockForUpdate()
                    ->first();

                if ($inv) {
                    $inv->increment('qty_pcs', $jeblosan['qty_pcs']);
                } else {
                    Inventory::create([
                        'item_id'      => $jeblosan['item_id'],
                        'warehouse_id' => $warehouseSawmill->id,
                        'qty_pcs'      => $jeblosan['qty_pcs'],
                        'qty_m3'       => $jeblosan['volume_m3'] ?? 0,
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $jeblosan['item_id'],
                    'warehouse_id'     => $warehouseSawmill->id,
                    'qty'              => $jeblosan['qty_pcs'],
                    'qty_m3'           => $jeblosan['volume_m3'] ?? 0,
                    'direction'        => 'IN',
                    'transaction_type' => 'SAWMILL',
                    'reference_type'   => $referenceId ? 'ProductionOrder' : 'SawmillProduction',
                    'reference_id'     => $referenceId ?? $production->id,
                    'reference_number' => $referenceNumber,
                    'notes'            => $isSisa
                        ? "Sisa Jeblosan Sawmill ({$documentNumber})"
                        : "Jeblosan Sawmill ({$documentNumber})",
                    'user_id'          => Auth::id(),
                ]);

                SawmillProductionRst::create([
                    'sawmill_production_id'    => $production->id,
                    'item_rst_id'              => $jeblosan['item_id'],
                    'qty_rst_pcs'              => $jeblosan['qty_pcs'],
                    'volume_rst_m3'            => $jeblosan['volume_m3'] ?? 0,
                    'is_sisa'                  => $isSisa,
                    'destination_warehouse_id' => $warehouseSawmill->id,
                ]);
            }

            // === OUTPUT 2: RST BASAH → GUDANG RSTB ===
            $warehouseRstb = Warehouse::where('code', 'RSTB')->firstOrFail();

            foreach ($data['rsts'] as $rst) {
                $inv = Inventory::where('item_id', $rst['item_rst_id'])
                    ->where('warehouse_id', $warehouseRstb->id)
                    ->lockForUpdate()
                    ->first();

                if ($inv) {
                    $inv->increment('qty_pcs', $rst['qty_rst_pcs']);
                } else {
                    Inventory::create([
                        'item_id'      => $rst['item_rst_id'],
                        'warehouse_id' => $warehouseRstb->id,
                        'qty_pcs'      => $rst['qty_rst_pcs'],
                        'qty_m3'       => $rst['volume_rst_m3'],
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $rst['item_rst_id'],
                    'warehouse_id'     => $warehouseRstb->id,
                    'qty'              => $rst['qty_rst_pcs'],
                    'qty_m3'           => $rst['volume_rst_m3'],
                    'direction'        => 'IN',
                    'transaction_type' => 'SAWMILL',
                    'reference_type'   => $referenceId ? 'ProductionOrder' : 'SawmillProduction',
                    'reference_id'     => $referenceId ?? $production->id,
                    'reference_number' => $referenceNumber,
                    'notes'            => "RST Basah hasil Sawmill ({$documentNumber})",
                    'user_id'          => Auth::id(),
                ]);

                SawmillProductionRst::create([
                    'sawmill_production_id'    => $production->id,
                    'item_rst_id'              => $rst['item_rst_id'],
                    'qty_rst_pcs'              => $rst['qty_rst_pcs'],
                    'volume_rst_m3'            => $rst['volume_rst_m3'],
                    'is_sisa'                  => false,
                    'destination_warehouse_id' => $warehouseRstb->id,
                ]);
            }

            $totalLogM3   = $production->logs()->sum('volume_log_m3');
            $totalRstM3   = $production->rsts()->sum('volume_rst_m3');
            $yieldPercent = $totalLogM3 > 0
                ? round(($totalRstM3 / $totalLogM3) * 100, 2)
                : 0;

            $production->update([
                'total_log_m3'  => $totalLogM3,
                'total_rst_m3'  => $totalRstM3,
                'yield_percent' => $yieldPercent,
            ]);

            if ($productionOrder) {
                $this->poProgress->markOnProgress($productionOrder->id);
            }

            return $production;
        });

        return response()->json([
            'success' => true,
            'message' => 'Produksi Sawmill berhasil dicatat.',
            'data'    => [
                'id'                     => $production->id,
                'document_number'        => $production->document_number,
                'estimated_finish_date'  => $production->estimated_finish_date,
                'total_log_m3'           => $production->total_log_m3,
                'total_rst_m3'           => $production->total_rst_m3,
                'yield_percent'          => $production->yield_percent,
                'ref_po_id'              => $production->ref_po_id,
            ],
        ], 201);
    }
}