<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SawmillProduction;
use App\Models\SawmillProductionLog;
use App\Models\SawmillProductionRst;
use App\Models\SawmillProductionJeblosan;
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

    // TASK 6: index()
    public function index(Request $request)
    {
        $productions = SawmillProduction::with([
            'logs.itemLog:id,name,code',
            'jeblosans.item:id,name,code',
            'jeblosans.rsts.itemRst:id,name,code',
            'warehouseFrom:id,name,code',
            'warehouseTo:id,name,code',
        ])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $productions,
        ]);
    }

    // TASK 4: store() — flow baru dengan jeblosan→RST nested
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'                  => ['required', 'date'],
            'estimated_finish_date' => ['nullable', 'date', 'after_or_equal:date'],
            'warehouse_from_id'     => ['required', 'exists:warehouses,id'],
            'warehouse_to_id'       => ['required', 'exists:warehouses,id'],
            'notes'                 => ['nullable', 'string'],
            'ref_po_id'             => ['nullable', 'integer', 'exists:production_orders,id'],
            'ref_product_id'        => ['nullable', 'integer'],

            // Logs opsional — boleh kosong jika mulai dari jeblosan existing
            'logs'               => ['nullable', 'array'],
            'logs.*.item_log_id' => ['required', 'exists:items,id'],
            'logs.*.qty_log_pcs' => ['required', 'integer', 'min:1'],

            // Jeblosans wajib min 1, RST nested di dalam tiap jeblosan
            'jeblosans'                        => ['required', 'array', 'min:1'],
            'jeblosans.*.item_id'              => ['required', 'exists:items,id'],
            'jeblosans.*.qty_pcs'              => ['required', 'integer', 'min:1'],
            'jeblosans.*.volume_m3'            => ['nullable', 'numeric', 'min:0'],
            'jeblosans.*.is_sisa'              => ['nullable', 'boolean'],
            'jeblosans.*.rsts'                 => ['nullable', 'array'],
            'jeblosans.*.rsts.*.item_rst_id'   => ['required', 'exists:items,id'],
            'jeblosans.*.rsts.*.qty_rst_pcs'   => ['required', 'integer', 'min:1'],
            'jeblosans.*.rsts.*.volume_rst_m3' => ['nullable', 'numeric', 'min:0'],
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
                'document_number'       => $documentNumber,
                'date'                  => $data['date'],
                'estimated_finish_date' => $data['estimated_finish_date'] ?? null,
                'warehouse_from_id'     => $data['warehouse_from_id'],
                'warehouse_to_id'       => $data['warehouse_to_id'],
                'notes'                 => $data['notes'] ?? null,
                'ref_po_id'             => $referenceId,
                'ref_product_id'        => $data['ref_product_id'] ?? null,
            ]);

            // 1. PROSES LOGS (opsional)
            foreach ($data['logs'] ?? [] as $log) {
                $inventory  = Inventory::where('item_id', $log['item_log_id'])
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

            // 2. PROSES JEBLOSANS + RSTs (nested)
            $warehouseSawmill = Warehouse::where('code', 'SAWMILL')->firstOrFail();
            $warehouseRstb    = Warehouse::where('code', 'RSTB')->firstOrFail();

            foreach ($data['jeblosans'] as $jeblosanData) {
                $isSisa     = $jeblosanData['is_sisa'] ?? false;
                $volJeblosan = $jeblosanData['volume_m3'] ?? 0;

                // a. Tambah stok jeblosan ke gudang SAWMILL
                $invJeblosan = Inventory::where('item_id', $jeblosanData['item_id'])
                    ->where('warehouse_id', $warehouseSawmill->id)
                    ->lockForUpdate()
                    ->first();

                if ($invJeblosan) {
                    $invJeblosan->increment('qty_pcs', $jeblosanData['qty_pcs']);
                } else {
                    Inventory::create([
                        'item_id'      => $jeblosanData['item_id'],
                        'warehouse_id' => $warehouseSawmill->id,
                        'qty_pcs'      => $jeblosanData['qty_pcs'],
                        'qty_m3'       => $volJeblosan,
                    ]);
                }

                // b. Simpan ke sawmill_production_jeblosans
                $jeblosanRecord = SawmillProductionJeblosan::create([
                    'sawmill_production_id' => $production->id,
                    'item_jeblosan_id'      => $jeblosanData['item_id'],
                    'qty_pcs'               => $jeblosanData['qty_pcs'],
                    'volume_m3'             => $volJeblosan,
                    'is_sisa'               => $isSisa,
                ]);

                // c. Inventory log IN untuk jeblosan
                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $jeblosanData['item_id'],
                    'warehouse_id'     => $warehouseSawmill->id,
                    'qty'              => $jeblosanData['qty_pcs'],
                    'qty_m3'           => $volJeblosan,
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

                // d. Loop RSTs di dalam jeblosan ini
                foreach ($jeblosanData['rsts'] ?? [] as $rst) {
                    $volRst = $rst['volume_rst_m3'] ?? 0;

                    $invRst = Inventory::where('item_id', $rst['item_rst_id'])
                        ->where('warehouse_id', $warehouseRstb->id)
                        ->lockForUpdate()
                        ->first();

                    if ($invRst) {
                        $invRst->increment('qty_pcs', $rst['qty_rst_pcs']);
                    } else {
                        Inventory::create([
                            'item_id'      => $rst['item_rst_id'],
                            'warehouse_id' => $warehouseRstb->id,
                            'qty_pcs'      => $rst['qty_rst_pcs'],
                            'qty_m3'       => $volRst,
                        ]);
                    }

                    SawmillProductionRst::create([
                        'sawmill_production_id'    => $production->id,
                        'jeblosan_id'              => $jeblosanRecord->id,
                        'item_rst_id'              => $rst['item_rst_id'],
                        'qty_rst_pcs'              => $rst['qty_rst_pcs'],
                        'volume_rst_m3'            => $volRst,
                        'is_sisa'                  => false,
                        'destination_warehouse_id' => $warehouseRstb->id,
                    ]);

                    InventoryLog::create([
                        'date'             => $data['date'],
                        'time'             => now()->toTimeString(),
                        'item_id'          => $rst['item_rst_id'],
                        'warehouse_id'     => $warehouseRstb->id,
                        'qty'              => $rst['qty_rst_pcs'],
                        'qty_m3'           => $volRst,
                        'direction'        => 'IN',
                        'transaction_type' => 'SAWMILL',
                        'reference_type'   => $referenceId ? 'ProductionOrder' : 'SawmillProduction',
                        'reference_id'     => $referenceId ?? $production->id,
                        'reference_number' => $referenceNumber,
                        'notes'            => "RST Basah dari Jeblosan #{$jeblosanRecord->id} ({$documentNumber})",
                        'user_id'          => Auth::id(),
                    ]);
                }
            }

            // 3. Hitung yield
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

            // 4. Update progress PO
            if ($productionOrder) {
                $this->poProgress->markOnProgress($productionOrder->id);
            }

            return $production;
        });

        return response()->json([
            'success' => true,
            'message' => 'Produksi Sawmill berhasil dicatat.',
            'data'    => [
                'id'                    => $production->id,
                'document_number'       => $production->document_number,
                'estimated_finish_date' => $production->estimated_finish_date,
                'total_log_m3'          => $production->total_log_m3,
                'total_rst_m3'          => $production->total_rst_m3,
                'yield_percent'         => $production->yield_percent,
                'ref_po_id'             => $production->ref_po_id,
            ],
        ], 201);
    }
}
