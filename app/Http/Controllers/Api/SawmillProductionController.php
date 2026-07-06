<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\SawmillProduction;
use App\Models\SawmillProductionLog;
use App\Models\SawmillProductionJeblosan;
use App\Models\SawmillProductionRst;
use App\Models\Warehouse;
use App\Services\ProductionOrderProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SawmillProductionController extends Controller
{
    protected ProductionOrderProgressService $poProgress;

    public function __construct(ProductionOrderProgressService $poProgress)
    {
        $this->poProgress = $poProgress;
    }

    // Stok jeblosan di Gudang SAWMILL — untuk input proses Jeblosan→RST
    public function getSawmillStock()
    {
        $warehouse = Warehouse::where('code', 'SAWMILL')->first();
        if (!$warehouse) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $items = Inventory::where('warehouse_id', $warehouse->id)
            ->where('qty_pcs', '>', 0)
            ->with('item')
            ->get()
            ->map(fn($inv) => [
                'item_id'       => $inv->item_id,
                'item_code'     => $inv->item?->code ?? '-',
                'item_name'     => $inv->item?->name ?? '-',
                'qty_available' => (float) $inv->qty_pcs,
                'volume_m3'     => (float) ($inv->item?->volume_m3 ?? 0),
            ]);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function index(Request $request)
    {
        $productions = SawmillProduction::with([
            'logs.itemLog:id,name,code',
            'jeblosans.item:id,name,code',
            'rsts.itemRst:id,name,code',
            'warehouseFrom:id,name,code',
            'warehouseTo:id,name,code',
        ])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json(['success' => true, 'data' => $productions]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'process_type'          => ['required', 'in:log_jeblosan,jeblosan_rst'],
            'date'                  => ['required', 'date'],
            'estimated_finish_date' => ['nullable', 'date'],
            'notes'                 => ['nullable', 'string'],
            'ref_po_id'             => ['nullable', 'integer', 'exists:production_orders,id'],

            // Proses Log→Jeblosan: input log (opsional)
            'logs'               => ['nullable', 'array'],
            'logs.*.item_log_id' => ['required_with:logs', 'integer', 'exists:items,id'],
            'logs.*.qty_log_pcs' => ['required_with:logs', 'integer', 'min:1'],

            // Proses Log→Jeblosan: output jeblosan (wajib jika log_jeblosan)
            'jeblosans'             => ['nullable', 'array'],
            'jeblosans.*.item_id'   => ['required_with:jeblosans', 'integer', 'exists:items,id'],
            'jeblosans.*.qty_pcs'   => ['required_with:jeblosans', 'integer', 'min:1'],
            'jeblosans.*.volume_m3' => ['nullable', 'numeric', 'min:0'],

            // Proses Jeblosan→RST: output RST (wajib jika jeblosan_rst)
            'rsts'                 => ['nullable', 'array'],
            'rsts.*.item_rst_id'   => ['required_with:rsts', 'integer', 'exists:items,id'],
            'rsts.*.qty_rst_pcs'   => ['required_with:rsts', 'integer', 'min:1'],
            'rsts.*.volume_rst_m3' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($data['process_type'] === 'log_jeblosan' && empty($data['jeblosans'])) {
            throw ValidationException::withMessages(['jeblosans' => ['Output jeblosan wajib diisi.']]);
        }
        if ($data['process_type'] === 'jeblosan_rst' && empty($data['jeblosans'])) {
            throw ValidationException::withMessages(['jeblosans' => ['Input jeblosan wajib diisi.']]);
        }
        if ($data['process_type'] === 'jeblosan_rst' && empty($data['rsts'])) {
            throw ValidationException::withMessages(['rsts' => ['Output RST wajib diisi.']]);
        }

        $production = DB::transaction(function () use ($data) {
            $isLogJeblosan  = $data['process_type'] === 'log_jeblosan';
            $prefix         = $isLogJeblosan ? 'SW' : 'RST';
            $runningNumber  = SawmillProduction::whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->where('process_type', $data['process_type'])
                ->count() + 1;
            $documentNumber = $prefix . '-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            $productionOrder = !empty($data['ref_po_id'])
                ? ProductionOrder::find($data['ref_po_id'])
                : null;
            $referenceId     = $productionOrder?->id ?? null;
            $referenceNumber = $productionOrder?->po_number ?? $documentNumber;

            $warehouseSawmill = Warehouse::where('code', 'SAWMILL')->firstOrFail();
            $warehouseRstb    = Warehouse::where('code', 'RSTB')->firstOrFail();

            $production = SawmillProduction::create([
                'document_number'       => $documentNumber,
                'process_type'          => $data['process_type'],
                'date'                  => $data['date'],
                'estimated_finish_date' => $data['estimated_finish_date'] ?? null,
                'warehouse_from_id'     => $isLogJeblosan ? $warehouseSawmill->id : $warehouseSawmill->id,
                'warehouse_to_id'       => $isLogJeblosan ? $warehouseSawmill->id : $warehouseRstb->id,
                'notes'                 => $data['notes'] ?? null,
                'ref_po_id'             => $referenceId,
            ]);

            if ($isLogJeblosan) {
                $this->processLogToJeblosan($data, $production, $warehouseSawmill, $referenceId, $referenceNumber, $documentNumber);
            } else {
                $this->processJeblosanToRst($data, $production, $warehouseSawmill, $warehouseRstb, $referenceId, $referenceNumber, $documentNumber);
            }

            if ($productionOrder) {
                $this->poProgress->markOnProgress($productionOrder->id);
            }

            return $production;
        });

        return response()->json([
            'success' => true,
            'message' => 'Produksi Sawmill berhasil dicatat.',
            'data'    => [
                'id'              => $production->id,
                'document_number' => $production->document_number,
                'process_type'    => $production->process_type,
            ],
        ], 201);
    }

    private function processLogToJeblosan(array $data, SawmillProduction $production, Warehouse $warehouseSawmill, ?int $referenceId, string $referenceNumber, string $documentNumber): void
    {
        $totalLogM3 = 0;
        $totalJeblosanM3 = 0;

        // INPUT: Kurangi stok log
        foreach ($data['logs'] ?? [] as $log) {
            $inv = Inventory::where('item_id', $log['item_log_id'])
                ->lockForUpdate()->first();

            if ($inv && $inv->qty_pcs >= $log['qty_log_pcs']) {
                $inv->decrement('qty_pcs', $log['qty_log_pcs']);
            }

            $item       = Item::find($log['item_log_id']);
            $volumeM3   = ($item?->volume_m3 ?? 0) * $log['qty_log_pcs'];
            $totalLogM3 += $volumeM3;

            InventoryLog::create([
                'date'             => $data['date'],
                'time'             => now()->toTimeString(),
                'item_id'          => $log['item_log_id'],
                'warehouse_id'     => $inv?->warehouse_id ?? $warehouseSawmill->id,
                'qty'              => $log['qty_log_pcs'],
                'qty_m3'           => $volumeM3,
                'direction'        => 'OUT',
                'transaction_type' => 'SAWMILL',
                'reference_type'   => $referenceId ? 'ProductionOrder' : 'SawmillProduction',
                'reference_id'     => $referenceId ?? $production->id,
                'reference_number' => $referenceNumber,
                'notes'            => "Log masuk sawmill ({$documentNumber})",
                'user_id'          => Auth::id(),
            ]);

            SawmillProductionLog::create([
                'sawmill_production_id' => $production->id,
                'item_log_id'           => $log['item_log_id'],
                'qty_log_pcs'           => $log['qty_log_pcs'],
                'volume_log_m3'         => $volumeM3,
            ]);
        }

        // OUTPUT: Tambah stok jeblosan ke Gudang SAWMILL
        foreach ($data['jeblosans'] as $jeb) {
            $volM3 = $jeb['volume_m3'] ?? 0;
            $totalJeblosanM3 += $volM3;

            $inv = Inventory::where('item_id', $jeb['item_id'])
                ->where('warehouse_id', $warehouseSawmill->id)
                ->lockForUpdate()->first();

            if ($inv) {
                $inv->increment('qty_pcs', $jeb['qty_pcs']);
            } else {
                Inventory::create([
                    'item_id'      => $jeb['item_id'],
                    'warehouse_id' => $warehouseSawmill->id,
                    'qty_pcs'      => $jeb['qty_pcs'],
                    'qty_m3'       => $volM3,
                ]);
            }

            InventoryLog::create([
                'date'             => $data['date'],
                'time'             => now()->toTimeString(),
                'item_id'          => $jeb['item_id'],
                'warehouse_id'     => $warehouseSawmill->id,
                'qty'              => $jeb['qty_pcs'],
                'qty_m3'           => $volM3,
                'direction'        => 'IN',
                'transaction_type' => 'SAWMILL',
                'reference_type'   => $referenceId ? 'ProductionOrder' : 'SawmillProduction',
                'reference_id'     => $referenceId ?? $production->id,
                'reference_number' => $referenceNumber,
                'notes'            => "Jeblosan hasil sawmill → Gudang SAWMILL ({$documentNumber})",
                'user_id'          => Auth::id(),
            ]);

            SawmillProductionJeblosan::create([
                'sawmill_production_id' => $production->id,
                'item_jeblosan_id'      => $jeb['item_id'],
                'qty_pcs'               => $jeb['qty_pcs'],
                'volume_m3'             => $volM3,
                'is_sisa'               => false,
            ]);
        }

        $yieldPercent = $totalLogM3 > 0 ? round($totalJeblosanM3 / $totalLogM3 * 100, 2) : 0;
        $production->update([
            'total_log_m3'  => $totalLogM3,
            'total_rst_m3'  => $totalJeblosanM3,
            'yield_percent' => $yieldPercent,
        ]);
    }

    private function processJeblosanToRst(array $data, SawmillProduction $production, Warehouse $warehouseSawmill, Warehouse $warehouseRstb, ?int $referenceId, string $referenceNumber, string $documentNumber): void
    {
        $totalInputM3 = 0;
        $totalRstM3   = 0;

        // INPUT: Kurangi stok jeblosan sesuai pilihan user (bukan ambil semua otomatis)
        foreach ($data['jeblosans'] as $jeb) {
            $inv = Inventory::where('item_id', $jeb['item_id'])
                ->where('warehouse_id', $warehouseSawmill->id)
                ->with('item')
                ->lockForUpdate()
                ->first();

            if (!$inv || $inv->qty_pcs < $jeb['qty_pcs']) {
                throw ValidationException::withMessages([
                    'jeblosans' => ['Stok jeblosan tidak mencukupi untuk item yang dipilih.'],
                ]);
            }

            $volM3 = ($inv->item?->volume_m3 ?? 0) * $jeb['qty_pcs'];
            $totalInputM3 += $volM3;

            $inv->decrement('qty_pcs', $jeb['qty_pcs']);

            InventoryLog::create([
                'date'             => $data['date'],
                'time'             => now()->toTimeString(),
                'item_id'          => $jeb['item_id'],
                'warehouse_id'     => $warehouseSawmill->id,
                'qty'              => $jeb['qty_pcs'],
                'qty_m3'           => $volM3,
                'direction'        => 'OUT',
                'transaction_type' => 'SAWMILL',
                'reference_type'   => $referenceId ? 'ProductionOrder' : 'SawmillProduction',
                'reference_id'     => $referenceId ?? $production->id,
                'reference_number' => $referenceNumber,
                'notes'            => "Jeblosan masuk proses RST ({$documentNumber})",
                'user_id'          => Auth::id(),
            ]);

            SawmillProductionLog::create([
                'sawmill_production_id' => $production->id,
                'item_log_id'           => $jeb['item_id'],
                'qty_log_pcs'           => $jeb['qty_pcs'],
                'volume_log_m3'         => $volM3,
            ]);
        }

        // OUTPUT: Tambah stok RST ke Gudang RSTB
        foreach ($data['rsts'] as $rst) {
            $volM3 = $rst['volume_rst_m3'] ?? 0;
            $totalRstM3 += $volM3;

            $inv = Inventory::where('item_id', $rst['item_rst_id'])
                ->where('warehouse_id', $warehouseRstb->id)
                ->lockForUpdate()->first();

            if ($inv) {
                $inv->increment('qty_pcs', $rst['qty_rst_pcs']);
            } else {
                Inventory::create([
                    'item_id'      => $rst['item_rst_id'],
                    'warehouse_id' => $warehouseRstb->id,
                    'qty_pcs'      => $rst['qty_rst_pcs'],
                    'qty_m3'       => $volM3,
                ]);
            }

            InventoryLog::create([
                'date'             => $data['date'],
                'time'             => now()->toTimeString(),
                'item_id'          => $rst['item_rst_id'],
                'warehouse_id'     => $warehouseRstb->id,
                'qty'              => $rst['qty_rst_pcs'],
                'qty_m3'           => $volM3,
                'direction'        => 'IN',
                'transaction_type' => 'SAWMILL',
                'reference_type'   => $referenceId ? 'ProductionOrder' : 'SawmillProduction',
                'reference_id'     => $referenceId ?? $production->id,
                'reference_number' => $referenceNumber,
                'notes'            => "RST hasil sawmill → Gudang RSTB ({$documentNumber})",
                'user_id'          => Auth::id(),
            ]);

            SawmillProductionRst::create([
                'sawmill_production_id'    => $production->id,
                'item_rst_id'              => $rst['item_rst_id'],
                'qty_rst_pcs'              => $rst['qty_rst_pcs'],
                'volume_rst_m3'            => $volM3,
                'destination_warehouse_id' => $warehouseRstb->id,
            ]);
        }

        $yieldPercent = $totalInputM3 > 0 ? round($totalRstM3 / $totalInputM3 * 100, 2) : 0;
        $production->update([
            'total_log_m3'  => $totalInputM3,
            'total_rst_m3'  => $totalRstM3,
            'yield_percent' => $yieldPercent,
        ]);
    }
}
