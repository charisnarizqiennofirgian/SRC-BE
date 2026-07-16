<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
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

    // Sementara: tampilkan SEMUA item Komponen yang punya stok (sama seperti Stock Index),
    // bukan cuma yang stoknya ada di gudang S4S. qty_available tetap dihitung khusus dari S4S
    // (bukan total semua gudang) karena itu yang benar-benar bisa dipakai sebagai input Mesin —
    // item yang qty_available = 0 di sini artinya stoknya sudah pindah ke gudang lain (mis. sudah
    // pernah diproses Mesin sebelumnya) dan tidak akan lolos validasi kalau tetap dipaksa input > 0.
    public function getS4sItems(Request $request)
    {
        $warehouseS4S = Warehouse::where('code', 'S4S')->first();
        $kategoriKomponen = Category::whereRaw('LOWER(name) LIKE ?', ['%komponen%'])->first();

        if (!$kategoriKomponen) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $items = Item::where('category_id', $kategoriKomponen->id)
            ->whereHas('inventories', fn ($q) => $q->where('qty_pcs', '>', 0))
            ->with(['inventories' => fn ($q) => $q->where('qty_pcs', '>', 0)])
            ->get()
            ->map(function ($item) use ($warehouseS4S) {
                $s4sQty = $warehouseS4S
                    ? (float) $item->inventories->where('warehouse_id', $warehouseS4S->id)->sum('qty_pcs')
                    : 0;
                $totalQty = (float) $item->inventories->sum('qty_pcs');

                return [
                    'item_id'       => $item->id,
                    'item_code'     => $item->code,
                    'item_name'     => $item->name,
                    'nama_produk'   => $item->nama_produk,
                    'qty_available' => $s4sQty,
                    'qty_total'     => $totalQty,
                ];
            });

        return response()->json(['success' => true, 'data' => $items]);
    }

    // store() — payload: production_order_detail_id + lines[]
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'                       => ['required', 'date'],
            'ref_po_id'                  => ['required', 'integer', 'exists:production_orders,id'],
            'production_order_detail_id' => ['required', 'integer', 'exists:production_order_details,id'],
            'qty_produk_jadi'            => ['nullable', 'numeric', 'min:0'],
            'notes'                      => ['nullable', 'string'],

            'lines'                   => ['required', 'array', 'min:1'],
            'lines.*.machine_id'      => ['required', 'integer', 'exists:machines,id'],
            'lines.*.input_item_id'   => ['required', 'integer', 'exists:items,id'],
            'lines.*.input_qty'       => ['required', 'numeric', 'min:0.01'],
            'lines.*.finishing'       => ['required', 'string', 'in:natural,warna'],
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

            // Pakai created_at (bukan kolom `date`, yang bisa di-backdate user) supaya konsisten
            // dengan prefix now()->format('Ym') di bawah — kalau pakai `date`, record dengan
            // tanggal produksi di bulan lain tidak ikut kehitung padahal prefix-nya sama.
            $runningNumber  = MesinProduction::whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count() + 1;
            $documentNumber = 'MSN-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            $mesinProduction = MesinProduction::create([
                'document_number'            => $documentNumber,
                'date'                       => $data['date'],
                'ref_po_id'                  => $data['ref_po_id'],
                'production_order_detail_id' => $detail->id,
                'qty_produk_jadi'            => $data['qty_produk_jadi'] ?? null,
                'notes'                      => $data['notes'] ?? null,
                'created_by'                 => Auth::id(),
            ]);

            $usedMachineNames = [];

            foreach ($data['lines'] as $idx => $line) {
                $itemId      = $line['input_item_id'];
                $inputQty    = $line['input_qty'];
                $finishing   = $line['finishing'];
                $bucket      = $finishing === 'warna' ? 'qty_warna' : 'qty_natural';
                $itemName    = Item::find($itemId)?->name ?? "ID {$itemId}";
                $machine     = Machine::find($line['machine_id']);
                $machineName = $machine?->name ?? '-';
                $usedMachineNames[$machineName] = true;

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

                // Komponen: validasi & kurangi breakdown natural/warna sesuai finishing baris ini
                // Ditulis ke items (global, kompatibilitas) dan inventories (per gudang, sumber baru
                // untuk Stock Index yang di-filter gudang)
                $inputItem = Item::lockForUpdate()->find($itemId);
                $sourceInvExtra = [];
                if ($inputItem && $inputItem->type === Item::TYPE_COMPONENT) {
                    $availableFinishing = (float) $inputItem->{$bucket};
                    if ($availableFinishing < $inputQty) {
                        $label = $finishing === 'warna' ? 'Warna' : 'Natural';
                        throw ValidationException::withMessages([
                            "lines.{$idx}.finishing" => [
                                "'{$itemName}' stok {$label} tidak cukup. Tersedia: {$availableFinishing}, dibutuhkan: {$inputQty}."
                            ],
                        ]);
                    }
                    $inputItem->{$bucket} = $availableFinishing - $inputQty;
                    $sourceInvExtra = [$bucket => max(0, (float) $sourceInv->{$bucket} - $inputQty)];
                }

                $sourceInv->decrement('qty_pcs', $inputQty, $sourceInvExtra);

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
                    'machine_id'          => $line['machine_id'],
                    'qty'                 => $inputQty,
                    'finishing'           => $finishing,
                ]);

                // === OUTPUT: tambah Komponen ke Gudang MESIN, link ke input ===
                $outInv = Inventory::where('item_id', $line['output_item_id'])
                    ->where('warehouse_id', $warehouseMesin->id)
                    ->lockForUpdate()
                    ->first();

                if ($outInv) {
                    $outInv->increment('qty_pcs', $line['output_qty']);
                } else {
                    $outInv = Inventory::create([
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

                // Komponen: tambah breakdown natural/warna item hasil (finishing sama dengan input,
                // karena item hasil mesin tetap item yang sama, cuma pindah gudang S4S → MESIN)
                if ((int) $line['output_item_id'] === (int) $itemId) {
                    if ($inputItem && $inputItem->type === Item::TYPE_COMPONENT) {
                        $inputItem->{$bucket} = (float) $inputItem->{$bucket} + $line['output_qty'];
                        $inputItem->stock     = (float) $inputItem->qty_natural + (float) $inputItem->qty_warna;
                        $inputItem->save();

                        // Inventories per gudang: item sama, cuma pindah S4S → MESIN
                        $outInv->{$bucket} = (float) $outInv->{$bucket} + $line['output_qty'];
                        $outInv->save();
                    }
                } else {
                    if ($inputItem && $inputItem->type === Item::TYPE_COMPONENT) {
                        $inputItem->stock = (float) $inputItem->qty_natural + (float) $inputItem->qty_warna;
                        $inputItem->save();
                    }
                    $outputItem = Item::lockForUpdate()->find($line['output_item_id']);
                    if ($outputItem && $outputItem->type === Item::TYPE_COMPONENT) {
                        $outputItem->{$bucket} = (float) $outputItem->{$bucket} + $line['output_qty'];
                        $outputItem->stock     = (float) $outputItem->qty_natural + (float) $outputItem->qty_warna;
                        $outputItem->save();

                        $outInv->{$bucket} = (float) $outInv->{$bucket} + $line['output_qty'];
                        $outInv->save();
                    }
                }

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
                        'machine_id'                => $line['machine_id'],
                        'keterangan'                => $line['reject_notes'] ?? null,
                    ]);
                }
            }

            // Update current_stage hanya untuk detail produk yang dipilih
            $detail->update(['current_stage' => 'mesin']);

            if ($productionOrder && $productionOrder->status !== 'in_progress') {
                $productionOrder->update(['status' => 'in_progress']);
            }

            $machineSummary = implode(', ', array_keys($usedMachineNames));

            return response()->json([
                'success' => true,
                'message' => "Proses Mesin ({$machineSummary}) berhasil dicatat ({$documentNumber}) untuk produk: {$detail->item?->name}",
                'data'    => [
                    'id'              => $mesinProduction->id,
                    'document_number' => $documentNumber,
                    'machine'         => $machineSummary,
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
