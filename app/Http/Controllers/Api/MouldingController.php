<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\MouldingProduction;
use App\Models\MouldingProductionInput;
use App\Models\MouldingProductionOutput;
use App\Models\MouldingProductionReject;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use App\Services\ProductionOrderProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MouldingController extends Controller
{
    protected ProductionOrderProgressService $poProgress;

    public function __construct(ProductionOrderProgressService $poProgress)
    {
        $this->poProgress = $poProgress;
    }

    public function getRstItems(Request $request)
    {
        $category = Category::where('name', 'Kayu RST')->first();
        if (!$category) {
            return response()->json(['success' => true, 'data' => []]);
        }
        $items = Item::where('category_id', $category->id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get()
            ->map(fn($i) => [
                'id'             => $i->id,
                'code'           => $i->code,
                'name'           => $i->name,
                'specifications' => $i->specifications,
                'volume_m3'      => $i->volume_m3,
            ]);
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function getKomponenItems(Request $request)
    {
        $excludeCategories = ['Kayu RST', 'Kayu Log', 'Produk Jadi'];
        $items = Item::whereHas('category', fn($q) => $q->whereNotIn('name', $excludeCategories))
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get()
            ->map(fn($i) => [
                'id'       => $i->id,
                'code'     => $i->code,
                'name'     => $i->name,
                'category' => $i->category?->name,
            ]);
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function getAvailablePos(Request $request)
    {
        $pos = ProductionOrder::where('status', '!=', 'completed')
            ->with(['salesOrder.buyer'])
            ->get()
            ->map(fn($po) => [
                'id'         => $po->id,
                'po_number'  => $po->po_number,
                'label'      => $po->po_number,
                'buyer_name' => $po->salesOrder?->buyer?->name ?? '-',
                'so_number'  => $po->salesOrder?->so_number ?? '-',
            ]);
        return response()->json(['success' => true, 'data' => $pos]);
    }

    // store() — payload: groups[] dengan inputs[] per group (N ukuran kayu → 1 komponen)
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'      => ['required', 'date'],
            'ref_po_id' => ['required', 'integer', 'exists:production_orders,id'],
            'notes'     => ['nullable', 'string'],

            'groups'                       => ['required', 'array', 'min:1'],
            'groups.*.output_item_id'      => ['required', 'integer', 'exists:items,id'],
            'groups.*.output_qty'          => ['required', 'numeric', 'min:1'],
            'groups.*.inputs'              => ['required', 'array', 'min:1'],
            'groups.*.inputs.*.item_id'    => ['required', 'integer', 'exists:items,id'],
            'groups.*.inputs.*.qty'        => ['required', 'numeric', 'min:0.01'],
            // Reject opsional per grup; item bisa RST atau Komponen
            'groups.*.reject_item_id'      => ['nullable', 'integer', 'exists:items,id'],
            'groups.*.reject_qty'          => ['nullable', 'numeric', 'min:0.01'],
            'groups.*.reject_type'         => ['nullable', 'string'],
            'groups.*.reject_notes'        => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data) {

            $warehouseS4S    = Warehouse::where('code', 'S4S')->first();
            $warehouseReject = Warehouse::where('code', 'REJECT')->first();

            if (!$warehouseS4S) {
                throw ValidationException::withMessages(['warehouse' => ['Gudang S4S tidak ditemukan.']]);
            }
            if (!$warehouseReject) {
                throw ValidationException::withMessages(['warehouse' => ['Gudang REJECT tidak ditemukan.']]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';

            $runningNumber  = MouldingProduction::whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->count() + 1;
            $documentNumber = 'MLD-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            $moulding = MouldingProduction::create([
                'document_number' => $documentNumber,
                'date'            => $data['date'],
                'ref_po_id'       => $data['ref_po_id'],
                'notes'           => $data['notes'] ?? null,
                'created_by'      => Auth::id(),
            ]);

            foreach ($data['groups'] as $gi => $group) {
                // === OUTPUT: buat record dulu (menjadi "grup header") ===
                $outputRecord = MouldingProductionOutput::create([
                    'moulding_production_id'       => $moulding->id,
                    'moulding_production_input_id' => null,
                    'item_id'                      => $group['output_item_id'],
                    'qty'                          => $group['output_qty'],
                ]);

                // === INPUTS: N ukuran kayu RST → kurangi stok masing-masing ===
                foreach ($group['inputs'] as $input) {
                    $gudangUrutan    = ['BUFFER', 'RSTK', 'RSTB'];
                    $sourceWarehouse = null;
                    $sourceInventory = null;

                    foreach ($gudangUrutan as $kode) {
                        $wh  = Warehouse::where('code', $kode)->first();
                        if (!$wh) continue;
                        $inv = Inventory::where('item_id', $input['item_id'])
                            ->where('warehouse_id', $wh->id)
                            ->where('qty_pcs', '>=', $input['qty'])
                            ->lockForUpdate()
                            ->first();
                        if ($inv) {
                            $sourceWarehouse = $wh;
                            $sourceInventory = $inv;
                            break;
                        }
                    }

                    if ($sourceInventory) {
                        $sourceInventory->decrement('qty_pcs', $input['qty']);
                        InventoryLog::create([
                            'date'             => $data['date'],
                            'time'             => now()->toTimeString(),
                            'item_id'          => $input['item_id'],
                            'warehouse_id'     => $sourceWarehouse->id,
                            'qty'              => $input['qty'],
                            'qty_m3'           => 0,
                            'direction'        => 'OUT',
                            'transaction_type' => 'MOULDING',
                            'reference_type'   => 'MouldingProduction',
                            'reference_id'     => $moulding->id,
                            'reference_number' => $documentNumber,
                            'notes'            => "RST masuk moulding ({$documentNumber}) dari {$sourceWarehouse->name}",
                            'user_id'          => Auth::id(),
                        ]);
                    } else {
                        Log::warning("Stok RST tidak cukup: item_id={$input['item_id']}");
                    }

                    MouldingProductionInput::create([
                        'moulding_production_id'        => $moulding->id,
                        'moulding_production_output_id' => $outputRecord->id,
                        'item_id'                       => $input['item_id'],
                        'qty'                           => $input['qty'],
                    ]);
                }

                // === OUTPUT INVENTORY: tambah Komponen ke S4S ===
                $outInv = Inventory::where('item_id', $group['output_item_id'])
                    ->where('warehouse_id', $warehouseS4S->id)
                    ->lockForUpdate()
                    ->first();

                if ($outInv) {
                    $outInv->increment('qty_pcs', $group['output_qty']);
                } else {
                    Inventory::create([
                        'item_id'      => $group['output_item_id'],
                        'warehouse_id' => $warehouseS4S->id,
                        'qty_pcs'      => $group['output_qty'],
                        'ref_po_id'    => $data['ref_po_id'],
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $group['output_item_id'],
                    'warehouse_id'     => $warehouseS4S->id,
                    'qty'              => $group['output_qty'],
                    'qty_m3'           => 0,
                    'direction'        => 'IN',
                    'transaction_type' => 'MOULDING',
                    'reference_type'   => 'MouldingProduction',
                    'reference_id'     => $moulding->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Komponen hasil moulding → S4S ({$documentNumber}) PO:{$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                // === REJECT (opsional per grup) ===
                if (!empty($group['reject_item_id']) && !empty($group['reject_qty'])) {
                    $rjInv = Inventory::where('item_id', $group['reject_item_id'])
                        ->where('warehouse_id', $warehouseReject->id)
                        ->lockForUpdate()
                        ->first();

                    if ($rjInv) {
                        $rjInv->increment('qty_pcs', $group['reject_qty']);
                    } else {
                        Inventory::create([
                            'item_id'      => $group['reject_item_id'],
                            'warehouse_id' => $warehouseReject->id,
                            'qty_pcs'      => $group['reject_qty'],
                        ]);
                    }

                    InventoryLog::create([
                        'date'             => $data['date'],
                        'time'             => now()->toTimeString(),
                        'item_id'          => $group['reject_item_id'],
                        'warehouse_id'     => $warehouseReject->id,
                        'qty'              => $group['reject_qty'],
                        'qty_m3'           => 0,
                        'direction'        => 'IN',
                        'transaction_type' => 'REJECT',
                        'reference_type'   => 'MouldingProduction',
                        'reference_id'     => $moulding->id,
                        'reference_number' => $documentNumber,
                        'notes'            => "Reject moulding grup #".($gi+1)." ({$documentNumber})",
                        'user_id'          => Auth::id(),
                    ]);

                    MouldingProductionReject::create([
                        'moulding_production_id'        => $moulding->id,
                        'moulding_production_output_id' => $outputRecord->id,
                        'item_id'                       => $group['reject_item_id'],
                        'qty'                           => $group['reject_qty'],
                        'reject_type'                   => $group['reject_type'] ?? 'moulding',
                        'keterangan'                    => $group['reject_notes'] ?? null,
                    ]);
                }
            }

            if ($productionOrder) {
                $productionOrder->current_stage = 'moulding';
                $productionOrder->status        = 'in_progress';
                $productionOrder->save();
            }

            return response()->json([
                'success' => true,
                'message' => "Proses Moulding berhasil ({$documentNumber})",
                'data'    => [
                    'id'              => $moulding->id,
                    'document_number' => $documentNumber,
                    'total_groups'    => count($data['groups']),
                ],
            ], 201);
        });
    }

    public function tandaiSelesai(Request $request, $id)
    {
        $productionOrder = ProductionOrder::findOrFail($id);
        $productionOrder->update(['current_stage' => 'mesin', 'status' => 'in_progress']);
        return response()->json([
            'success' => true,
            'message' => "PO {$productionOrder->po_number} selesai moulding, lanjut ke Mesin.",
        ]);
    }
}
