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
            'date' => ['required', 'date'],
            'warehouse_from_id' => ['required', 'exists:warehouses,id'],
            'warehouse_to_id' => ['required', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],

            'ref_po_id' => ['required', 'integer', 'exists:production_orders,id'],
            'ref_product_id' => ['nullable', 'integer'],

            'logs' => ['required', 'array', 'min:1'],
            'logs.*.item_log_id' => ['required', 'exists:items,id'],
            'logs.*.qty_log_pcs' => ['required', 'integer', 'min:1'],

            'rsts' => ['required', 'array', 'min:1'],
            'rsts.*.item_rst_id' => ['required', 'exists:items,id'],
            'rsts.*.qty_rst_pcs' => ['required', 'integer', 'min:1'],
            'rsts.*.volume_rst_m3' => ['required', 'numeric', 'min:0'],
        ]);

        $production = DB::transaction(function () use ($data) {
            $runningNumber = SawmillProduction::whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->count() + 1;

            $documentNumber = 'SW-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            $production = SawmillProduction::create([
                'document_number'   => $documentNumber,
                'date'              => $data['date'],
                'warehouse_from_id' => $data['warehouse_from_id'],
                'warehouse_to_id'   => $data['warehouse_to_id'],
                'notes'             => $data['notes'] ?? null,
                'ref_po_id'         => $data['ref_po_id'],
                'ref_product_id'    => $data['ref_product_id'] ?? null,
            ]);

            // Ambil data PO untuk reference_number
            $productionOrder = ProductionOrder::find($data['ref_po_id']);

            // Kurangi stok LOG di gudang asal
            foreach ($data['logs'] as $log) {
                $inventory = Inventory::where('item_id', $log['item_log_id'])
                    ->where('warehouse_id', $data['warehouse_from_id'])
                    ->lockForUpdate()
                    ->first();

                $currentQty = $inventory?->qty ?? 0;

                if ($currentQty < $log['qty_log_pcs']) {
                    throw ValidationException::withMessages([
                        'logs' => ["Stok log untuk item {$log['item_log_id']} di gudang asal tidak mencukupi. (Tersedia: {$currentQty})"],
                    ]);
                }

                if ($inventory) {
                    $inventory->decrement('qty', $log['qty_log_pcs']);
                }

                // Catat ke inventory_logs (OUT dari gudang asal)
                InventoryLog::create([
                    'date' => $data['date'],
                    'time' => now()->toTimeString(),
                    'item_id' => $log['item_log_id'],
                    'warehouse_id' => $data['warehouse_from_id'],
                    'qty' => $log['qty_log_pcs'],
                    'direction' => 'OUT',
                    'transaction_type' => 'PRODUCTION',
                    'reference_type' => 'ProductionOrder',
                    'reference_id' => $data['ref_po_id'],
                    'reference_number' => $productionOrder?->po_number ?? $documentNumber,
                    'notes' => "Bahan log untuk produksi sawmill ({$documentNumber})",
                    'user_id' => Auth::id(),
                ]);

                $itemLog        = Item::find($log['item_log_id']);
                $volumePerPcs   = $itemLog?->volume_m3 ?? 0;
                $volumeLogTotal = $log['qty_log_pcs'] * $volumePerPcs;

                SawmillProductionLog::create([
                    'sawmill_production_id' => $production->id,
                    'item_log_id'           => $log['item_log_id'],
                    'qty_log_pcs'           => $log['qty_log_pcs'],
                    'volume_log_m3'         => $volumeLogTotal,
                ]);
            }

            // Tambah stok RST di gudang tujuan
            foreach ($data['rsts'] as $rst) {
                $inventory = Inventory::where('item_id', $rst['item_rst_id'])
                    ->where('warehouse_id', $data['warehouse_to_id'])
                    ->lockForUpdate()
                    ->first();

                if ($inventory) {
                    $inventory->increment('qty', $rst['qty_rst_pcs']);
                } else {
                    Inventory::create([
                        'item_id'      => $rst['item_rst_id'],
                        'warehouse_id' => $data['warehouse_to_id'],
                        'qty'          => $rst['qty_rst_pcs'],
                    ]);
                }

                // Catat ke inventory_logs (IN ke gudang tujuan)
                InventoryLog::create([
                    'date' => $data['date'],
                    'time' => now()->toTimeString(),
                    'item_id' => $rst['item_rst_id'],
                    'warehouse_id' => $data['warehouse_to_id'],
                    'qty' => $rst['qty_rst_pcs'],
                    'qty_m3' => $rst['volume_rst_m3'],
                    'direction' => 'IN',
                    'transaction_type' => 'PRODUCTION',
                    'reference_type' => 'ProductionOrder',
                    'reference_id' => $data['ref_po_id'],
                    'reference_number' => $productionOrder?->po_number ?? $documentNumber,
                    'notes' => "Hasil produksi sawmill RST ({$documentNumber})",
                    'user_id' => Auth::id(),
                ]);

                SawmillProductionRst::create([
                    'sawmill_production_id' => $production->id,
                    'item_rst_id'           => $rst['item_rst_id'],
                    'qty_rst_pcs'           => $rst['qty_rst_pcs'],
                    'volume_rst_m3'         => $rst['volume_rst_m3'],
                ]);
            }

            // === HITUNG TOTAL & RENDEMEN ===
            $totalLogM3   = $production->logs()->sum('volume_log_m3');
            $totalRstM3   = $production->rsts()->sum('volume_rst_m3');
            $yieldPercent = $totalLogM3 > 0
                ? ($totalRstM3 / $totalLogM3) * 100
                : 0;

            $production->update([
                'total_log_m3'  => $totalLogM3,
                'total_rst_m3'  => $totalRstM3,
                'yield_percent' => $yieldPercent,
            ]);

            return $production;
        });

        // Setelah transaksi sukses, update status PO jadi on_progress kalau masih draft
        $this->poProgress->markOnProgress($data['ref_po_id']);

        return response()->json([
            'success' => true,
            'data'    => $production->load('logs', 'rsts'),
        ], 201);
    }
}
