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
use App\Models\ProductionOrderDetail;
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

    public function getMachines()
    {
        $machines = Machine::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'description']);
        return response()->json(['success' => true, 'data' => $machines]);
    }

    public function getAvailablePos()
    {
        $pos = ProductionOrder::where('status', '!=', 'completed')
            ->with(['salesOrder.buyer', 'details'])
            ->get()
            ->map(fn($po) => [
                'id'               => $po->id,
                'po_number'        => $po->po_number,
                'label'            => $po->po_number,
                'buyer_name'       => $po->salesOrder?->buyer?->name ?? '-',
                'so_number'        => $po->salesOrder?->so_number ?? '-',
                'total_items'      => $po->details->count(),
                'mesin_done_count' => $po->details->where('current_stage', 'mesin')->count(),
            ]);
        return response()->json(['success' => true, 'data' => $pos]);
    }

    // GET /operator-mesin/po-detail-items/{poId}
    public function getPoDetailItems(Request $request, $poId)
    {
        ProductionOrder::findOrFail($poId);

        $details = ProductionOrderDetail::where('production_order_id', $poId)
            ->with('item')
            ->get()
            ->map(fn($d) => [
                'id'            => $d->id,
                'item_id'       => $d->item_id,
                'item_name'     => $d->item?->name ?? '-',
                'item_code'     => $d->item?->code ?? '-',
                'qty_planned'   => $d->qty_planned,
                'current_stage' => $d->current_stage,
                'mesin_done'    => $d->current_stage === 'mesin',
            ]);

        return response()->json(['success' => true, 'data' => $details]);
    }

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
            ->map(fn($inv) => [
                'id'            => $inv->id,
                'item_id'       => $inv->item_id,
                'item_code'     => $inv->item?->code ?? '-',
                'item_name'     => $inv->item?->name ?? '-',
                'nama_produk'   => $inv->item?->nama_produk ?? null,
                'qty_available' => (float) $inv->qty_pcs,
            ]);
        return response()->json(['success' => true, 'data' => $inventories]);
    }

    // store() — payload: production_order_detail_id + lines[]
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'                       => ['required', 'date'],
            'ref_po_id'                  => ['required', 'integer', 'exists:production_orders,id'],
            'production_order_detail_id' => ['required', 'integer', 'exists:production_order_details,id'],
            'machine_id'                 => ['required', 'integer', 'exists:machines,id'],
            'notes'                      => ['nullable', 'string'],

            'lines'                   => ['required', 'array', 'min:1'],
            'lines.*.input_item_id'   => ['required', 'integer', 'exists:items,id'],
            'lines.*.input_qty'       => ['required', 'numeric', 'min:0.01'],
            'lines.*.output_item_id'  => ['required', 'integer', 'exists:items,id'],
            'lines.*.output_qty'      => ['required', 'numeric', 'min:1'],
            // Reject komponen saja; item = input_item
            'lines.*.reject_qty'      => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.reject_notes'    => ['nullable', 'string'],
        ]);

        $detail = ProductionOrderDetail::where('id', $data['production_order_detail_id'])
            ->where('production_order_id', $data['ref_po_id'])
            ->firstOrFail();

        return DB::transaction(function () use ($data, $detail) {

            $warehouseS4S    = Warehouse::where('code', 'S4S')->first();
            $warehouseMesin  = Warehouse::where('code', 'MESIN')->first();
            $warehouseReject = Warehouse::where('code', 'REJECT')->first();

            if (!$warehouseS4S)    throw ValidationException::withMessages(['warehouse' => ['Gudang S4S tidak ditemukan.']]);
            if (!$warehouseMesin)  throw ValidationException::withMessages(['warehouse' => ['Gudang MESIN tidak ditemukan.']]);
            if (!$warehouseReject) throw ValidationException::withMessages(['warehouse' => ['Gudang REJECT tidak ditemukan.']]);

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';
            $machine         = Machine::find($data['machine_id']);
            $machineName     = $machine?->name ?? '-';

            $runningNumber  = MesinProduction::whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->count() + 1;
            $documentNumber = 'MSN-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            $mesinProduction = MesinProduction::create([
                'document_number'            => $documentNumber,
                'date'                       => $data['date'],
                'ref_po_id'                  => $data['ref_po_id'],
                'production_order_detail_id' => $detail->id,
                'machine_id'                 => $data['machine_id'],
                'notes'                      => $data['notes'] ?? null,
                'created_by'                 => Auth::id(),
            ]);

            foreach ($data['lines'] as $idx => $line) {
                $itemId   = $line['input_item_id'];
                $inputQty = $line['input_qty'];
                $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                // === INPUT: kurangi Komponen dari S4S ===
                $sourceInv    = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $warehouseS4S->id)
                    ->lockForUpdate()
                    ->first();
                $availableQty = $sourceInv?->qty_pcs ?? 0;

                if ($availableQty < $inputQty) {
                    throw ValidationException::withMessages([
                        "lines.{$idx}.input_qty" => [
                            "'{$itemName}' stok S4S tidak cukup. Tersedia: {$availableQty}, dibutuhkan: {$inputQty}."
                        ],
                    ]);
                }

                $sourceInv->decrement('qty_pcs', $inputQty);

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $warehouseS4S->id,
                    'qty'              => $inputQty,
                    'qty_m3'           => 0,
                    'direction'        => 'OUT',
                    'transaction_type' => 'MESIN',
                    'reference_type'   => 'MesinProduction',
                    'reference_id'     => $mesinProduction->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Masuk mesin {$machineName} ({$documentNumber}) PO:{$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                $inputRecord = MesinProductionInput::create([
                    'mesin_production_id' => $mesinProduction->id,
                    'item_id'             => $itemId,
                    'qty'                 => $inputQty,
                ]);

                // === OUTPUT: tambah Komponen ke Gudang MESIN, link ke input ===
                $outInv = Inventory::where('item_id', $line['output_item_id'])
                    ->where('warehouse_id', $warehouseMesin->id)
                    ->lockForUpdate()
                    ->first();

                if ($outInv) {
                    $outInv->increment('qty_pcs', $line['output_qty']);
                } else {
                    Inventory::create([
                        'item_id'      => $line['output_item_id'],
                        'warehouse_id' => $warehouseMesin->id,
                        'qty_pcs'      => $line['output_qty'],
                        'ref_po_id'    => $data['ref_po_id'],
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $line['output_item_id'],
                    'warehouse_id'     => $warehouseMesin->id,
                    'qty'              => $line['output_qty'],
                    'qty_m3'           => 0,
                    'direction'        => 'IN',
                    'transaction_type' => 'MESIN',
                    'reference_type'   => 'MesinProduction',
                    'reference_id'     => $mesinProduction->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Hasil mesin {$machineName} → Gudang MESIN ({$documentNumber}) PO:{$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                MesinProductionOutput::create([
                    'mesin_production_id'      => $mesinProduction->id,
                    'mesin_production_input_id' => $inputRecord->id,
                    'item_id'                  => $line['output_item_id'],
                    'qty'                      => $line['output_qty'],
                ]);

                // === REJECT (opsional): selalu Komponen input yang rusak ===
                if (!empty($line['reject_qty'])) {
                    $rjInv = Inventory::where('item_id', $itemId)
                        ->where('warehouse_id', $warehouseReject->id)
                        ->lockForUpdate()
                        ->first();

                    if ($rjInv) {
                        $rjInv->increment('qty_pcs', $line['reject_qty']);
                    } else {
                        Inventory::create([
                            'item_id'      => $itemId,
                            'warehouse_id' => $warehouseReject->id,
                            'qty_pcs'      => $line['reject_qty'],
                        ]);
                    }

                    InventoryLog::create([
                        'date'             => $data['date'],
                        'time'             => now()->toTimeString(),
                        'item_id'          => $itemId,
                        'warehouse_id'     => $warehouseReject->id,
                        'qty'              => $line['reject_qty'],
                        'qty_m3'           => 0,
                        'direction'        => 'IN',
                        'transaction_type' => 'REJECT',
                        'reference_type'   => 'MesinProduction',
                        'reference_id'     => $mesinProduction->id,
                        'reference_number' => $documentNumber,
                        'notes'            => "Reject mesin {$machineName} baris #".($idx+1)." ({$documentNumber})",
                        'user_id'          => Auth::id(),
                    ]);

                    MesinProductionReject::create([
                        'mesin_production_id'       => $mesinProduction->id,
                        'mesin_production_input_id' => $inputRecord->id,
                        'item_id'                   => $itemId,
                        'qty'                       => $line['reject_qty'],
                        'machine_id'                => $data['machine_id'],
                        'keterangan'                => $line['reject_notes'] ?? null,
                    ]);
                }
            }

            // Update current_stage hanya untuk detail produk yang dipilih
            $detail->update(['current_stage' => 'mesin']);

            if ($productionOrder && $productionOrder->status !== 'in_progress') {
                $productionOrder->update(['status' => 'in_progress']);
            }

            return response()->json([
                'success' => true,
                'message' => "Proses Mesin ({$machineName}) berhasil dicatat ({$documentNumber}) untuk produk: {$detail->item?->name}",
                'data'    => [
                    'id'              => $mesinProduction->id,
                    'document_number' => $documentNumber,
                    'machine'         => $machineName,
                    'total_lines'     => count($data['lines']),
                    'product_name'    => $detail->item?->name,
                ],
            ], 201);
        });
    }

    public function tandaiSelesai(Request $request, $poId)
    {
        $productionOrder = ProductionOrder::with('details.item')->findOrFail($poId);

        $allDetails   = $productionOrder->details;
        $totalDetails = $allDetails->count();
        $doneDetails  = $allDetails->where('current_stage', 'mesin')->count();

        if ($totalDetails === 0) {
            return response()->json(['success' => false, 'message' => 'PO ini tidak memiliki detail produk.'], 422);
        }

        if ($doneDetails < $totalDetails) {
            $belumSelesai = $allDetails
                ->where('current_stage', '!=', 'mesin')
                ->map(fn($d) => $d->item?->name ?? 'Item #'.$d->item_id)
                ->values()->all();

            return response()->json([
                'success' => false,
                'message' => "Belum semua produk selesai proses Mesin ({$doneDetails}/{$totalDetails}). Produk yang belum: " . implode(', ', $belumSelesai),
                'pending' => $belumSelesai,
            ], 422);
        }

        $productionOrder->update(['current_stage' => 'assembly', 'status' => 'in_progress']);

        return response()->json([
            'success' => true,
            'message' => "Semua produk ({$totalDetails}) selesai proses Mesin. PO {$productionOrder->po_number} lanjut ke Assembling.",
        ]);
    }
}
