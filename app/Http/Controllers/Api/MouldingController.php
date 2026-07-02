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
use App\Models\ProductionOrderDetail;
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
                'id'         => $i->id,
                'code'       => $i->code,
                'name'       => $i->name,
                'category'   => $i->category?->name,
                'nama_produk' => $i->nama_produk,
            ]);
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function getAvailablePos(Request $request)
    {
        $query = ProductionOrder::where('status', '!=', 'completed')
            ->with(['salesOrder.buyer', 'details.item']);

        if ($request->filled('type') && in_array($request->type, ['production', 'sample'])) {
            $query->where('type', $request->type);
        }

        $pos = $query->get()
            ->map(fn($po) => [
                'id'         => $po->id,
                'po_number'  => $po->po_number,
                'label'      => $po->po_number,
                'buyer_name' => $po->salesOrder?->buyer?->name ?? '-',
                'so_number'  => $po->salesOrder?->so_number ?? '-',
                'total_items'       => $po->details->count(),
                'moulding_done_count' => $po->details->where('current_stage', 'moulding')->count(),
            ]);
        return response()->json(['success' => true, 'data' => $pos]);
    }

    // GET /produksi/moulding/po-detail-items/{poId}
    // Return list produk dalam PO beserta status moulding-nya
    public function getPoDetailItems(Request $request, $poId)
    {
        $po = ProductionOrder::with('salesOrder.details')->findOrFail($poId);

        $details = ProductionOrderDetail::where('production_order_id', $poId)
            ->with('item')
            ->get();

        // Auto-repair: PO lama mungkin tidak punya production_order_details.
        // Jika kosong tapi SO masih punya detail items, buat records-nya sekarang.
        if ($details->isEmpty() && $po->salesOrder && $po->salesOrder->details->isNotEmpty()) {
            DB::beginTransaction();
            try {
                foreach ($po->salesOrder->details as $sd) {
                    ProductionOrderDetail::create([
                        'production_order_id'   => $po->id,
                        'sales_order_detail_id' => $sd->id,
                        'item_id'               => $sd->item_id,
                        'qty_planned'           => $sd->quantity,
                        'qty_produced'          => 0,
                    ]);
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::warning("Auto-repair production_order_details gagal untuk PO {$poId}: " . $e->getMessage());
            }

            $details = ProductionOrderDetail::where('production_order_id', $poId)
                ->with('item')
                ->get();
        }

        // Map SO details by id untuk fallback nama ketika item di-soft-delete
        $soDetails = $po->salesOrder
            ? $po->salesOrder->details->keyBy('id')
            : collect();

        $mapped = $details->map(fn($d) => [
            'id'            => $d->id,
            'item_id'       => $d->item_id,
            'item_name'     => $d->item?->name
                                ?? $soDetails->get($d->sales_order_detail_id)?->item_name
                                ?? '-',
            'item_code'     => $d->item?->code
                                ?? $soDetails->get($d->sales_order_detail_id)?->item_code
                                ?? '-',
            'qty_planned'   => $d->qty_planned,
            'current_stage' => $d->current_stage,
            'moulding_done' => $d->current_stage === 'moulding',
        ]);

        return response()->json(['success' => true, 'data' => $mapped]);
    }

    // store() — payload: production_order_detail_id + groups[] dengan inputs[] per group
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'                         => ['required', 'date'],
            'ref_po_id'                    => ['required', 'integer', 'exists:production_orders,id'],
            'production_order_detail_id'   => ['required', 'integer', 'exists:production_order_details,id'],
            'notes'                        => ['nullable', 'string'],

            'groups'                       => ['required', 'array', 'min:1'],
            'groups.*.output_item_id'      => ['required', 'integer', 'exists:items,id'],
            'groups.*.output_qty'          => ['required', 'numeric', 'min:1'],
            'groups.*.finishing'           => ['required', 'string', 'in:natural,warna'],
            'groups.*.inputs'              => ['required', 'array', 'min:1'],
            'groups.*.inputs.*.item_id'    => ['required', 'integer', 'exists:items,id'],
            'groups.*.inputs.*.qty'        => ['required', 'numeric', 'min:0.01'],
            'groups.*.reject_item_id'      => ['nullable', 'integer', 'exists:items,id'],
            'groups.*.reject_qty'          => ['nullable', 'numeric', 'min:0.01'],
            'groups.*.reject_type'         => ['nullable', 'string'],
            'groups.*.reject_notes'        => ['nullable', 'string'],
        ]);

        // Pastikan detail ini milik PO yang dipilih
        $detail = ProductionOrderDetail::where('id', $data['production_order_detail_id'])
            ->where('production_order_id', $data['ref_po_id'])
            ->firstOrFail();

        return DB::transaction(function () use ($data, $detail) {

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

            $prefix = 'MLD-' . now()->format('Ym') . '-';
            $last   = MouldingProduction::where('document_number', 'like', $prefix . '%')
                ->orderByDesc('document_number')
                ->value('document_number');
            $runningNumber  = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
            $documentNumber = $prefix . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            $moulding = MouldingProduction::create([
                'document_number'            => $documentNumber,
                'date'                       => $data['date'],
                'ref_po_id'                  => $data['ref_po_id'],
                'production_order_detail_id' => $detail->id,
                'notes'                      => $data['notes'] ?? null,
                'created_by'                 => Auth::id(),
            ]);

            foreach ($data['groups'] as $gi => $group) {
                $outputRecord = MouldingProductionOutput::create([
                    'moulding_production_id'       => $moulding->id,
                    'moulding_production_input_id' => null,
                    'item_id'                      => $group['output_item_id'],
                    'qty'                          => $group['output_qty'],
                    'finishing'                    => $group['finishing'],
                ]);

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

                // Komponen: pecah stok ke qty_natural / qty_warna sesuai finishing hasil moulding
                $outputItem = Item::lockForUpdate()->find($group['output_item_id']);
                if ($outputItem && $outputItem->type === Item::TYPE_COMPONENT) {
                    if ($group['finishing'] === 'warna') {
                        $outputItem->qty_warna = (float) $outputItem->qty_warna + $group['output_qty'];
                    } else {
                        $outputItem->qty_natural = (float) $outputItem->qty_natural + $group['output_qty'];
                    }
                    $outputItem->stock = (float) $outputItem->qty_natural + (float) $outputItem->qty_warna;
                    $outputItem->save();
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
                    'notes'            => "Komponen hasil moulding → S4S ({$documentNumber}) PO:{$poNumber} Produk:{$detail->item?->name}",
                    'user_id'          => Auth::id(),
                ]);

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

            // Update current_stage hanya untuk detail produk yang dipilih
            $detail->update(['current_stage' => 'moulding']);

            // Update status PO jadi in_progress (tapi tidak update current_stage PO di sini)
            if ($productionOrder && $productionOrder->status !== 'in_progress') {
                $productionOrder->update(['status' => 'in_progress']);
            }

            return response()->json([
                'success' => true,
                'message' => "Proses Moulding berhasil ({$documentNumber}) untuk produk: {$detail->item?->name}",
                'data'    => [
                    'id'              => $moulding->id,
                    'document_number' => $documentNumber,
                    'total_groups'    => count($data['groups']),
                    'product_name'    => $detail->item?->name,
                ],
            ], 201);
        });
    }

    public function tandaiSelesai(Request $request, $id)
    {
        $productionOrder = ProductionOrder::with('details.item')->findOrFail($id);

        $allDetails    = $productionOrder->details;
        $totalDetails  = $allDetails->count();
        $doneDetails   = $allDetails->where('current_stage', 'moulding')->count();

        if ($totalDetails === 0) {
            return response()->json(['success' => false, 'message' => 'PO ini tidak memiliki detail produk.'], 422);
        }

        if ($doneDetails < $totalDetails) {
            $belumSelesai = $allDetails
                ->where('current_stage', '!=', 'moulding')
                ->map(fn($d) => $d->item?->name ?? 'Item #'.$d->item_id)
                ->values()
                ->all();

            return response()->json([
                'success' => false,
                'message' => "Belum semua produk selesai moulding ({$doneDetails}/{$totalDetails}). Produk yang belum: " . implode(', ', $belumSelesai),
                'pending' => $belumSelesai,
            ], 422);
        }

        $productionOrder->update(['current_stage' => 'mesin', 'status' => 'in_progress']);

        return response()->json([
            'success' => true,
            'message' => "Semua produk ({$totalDetails}) selesai moulding. PO {$productionOrder->po_number} lanjut ke Mesin.",
        ]);
    }
}
