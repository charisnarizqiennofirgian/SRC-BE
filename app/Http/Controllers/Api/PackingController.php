<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PackingController extends Controller
{
    // Gudang yang bisa jadi sumber komponen untuk "Rakit dari Komponen" saat packing
    private const COMPONENT_WAREHOUSE_CODES = [
        'S4S', 'MESIN', 'RUSKOMP', 'ASSEMBLING', 'SANDING', 'RUSTIK', 'FINISHING', 'QC_FINAL',
    ];

    // =============================================
    // GET: Available POs
    // =============================================
    public function getAvailablePos()
    {
        $pos = ProductionOrder::where('status', '!=', 'completed')
            ->with(['salesOrder.buyer', 'details.item'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($po) {
                return [
                    'id'         => $po->id,
                    'po_number'  => $po->po_number,
                    'label'      => $po->po_number,
                    'buyer_name' => $po->salesOrder?->buyer?->name ?? '-',
                    'so_number'  => $po->salesOrder?->so_number ?? '-',
                    'details'    => $po->details->map(function ($d) {
                        return [
                            'item_id'     => $d->item_id,
                            'item_code'   => $d->item?->code ?? '-',
                            'item_name'   => $d->item?->name ?? '-',
                            'qty_planned' => (float) $d->qty_planned,
                        ];
                    }),
                ];
            });

        return response()->json(['success' => true, 'data' => $pos]);
    }

    // =============================================
    // GET: Item komponen yang bisa dipakai untuk "Rakit dari Komponen"
    // Diambil dari semua gudang tahap produksi (S4S/MESIN/RUSKOMP/ASSEMBLING/dst)
    // =============================================
    public function getComponentSourceItems()
    {
        $warehouses = Warehouse::whereIn('code', self::COMPONENT_WAREHOUSE_CODES)->get();

        if ($warehouses->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $inventories = Inventory::whereIn('warehouse_id', $warehouses->pluck('id'))
            ->where('qty_pcs', '>', 0)
            ->with(['item', 'warehouse'])
            ->get()
            ->map(fn($inv) => [
                'item_id'        => $inv->item_id,
                'item_code'      => $inv->item?->code ?? '-',
                'item_name'      => $inv->item?->name ?? '-',
                'nama_produk'    => $inv->item?->nama_produk ?? null,
                'item_type'      => $inv->item?->type ?? null,
                'qty_available'  => (float) $inv->qty_pcs,
                'warehouse_id'   => $inv->warehouse_id,
                'warehouse_code' => $inv->warehouse?->code ?? '-',
                'warehouse_name' => $inv->warehouse?->name ?? '-',
            ]);

        return response()->json(['success' => true, 'data' => $inventories]);
    }

    // =============================================
    // POST: Simpan proses packing
    // Output: stok keluar dari gudang sumber → masuk Gudang Packing
    // =============================================
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'                => ['required', 'date'],
            'ref_po_id'           => ['required', 'integer', 'exists:production_orders,id'],
            'source_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'notes'               => ['nullable', 'string'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.item_id'     => ['required', 'integer', 'exists:items,id'],
            'items.*.qty'         => ['required', 'numeric', 'min:1'],

            // Opsional: rakit dari komponen langsung saat packing (skip input manual di menu Assembling)
            'items.*.components'                => ['nullable', 'array'],
            'items.*.components.*.item_id'      => ['required_with:items.*.components', 'integer', 'exists:items,id'],
            'items.*.components.*.warehouse_id' => ['required_with:items.*.components', 'integer', 'exists:warehouses,id'],
            'items.*.components.*.qty'          => ['required_with:items.*.components', 'numeric', 'min:0.01'],
            'items.*.components.*.finishing'    => ['nullable', 'string', 'in:natural,warna'],
        ]);

        return DB::transaction(function () use ($data) {
            Log::info('=== PACKING START ===', ['po_id' => $data['ref_po_id']]);

            $warehousePacking = Warehouse::where('code', 'PACKING')->first();
            $sourceWarehouse  = Warehouse::findOrFail($data['source_warehouse_id']);

            if (!$warehousePacking) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Packing tidak ditemukan.'],
                ]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';

            // === NOMOR DOKUMEN ===
            $runningNumber  = InventoryLog::where('transaction_type', 'PACKING')
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->where('direction', 'IN')
                ->count() + 1;
            $documentNumber = 'PKG-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            foreach ($data['items'] as $item) {
                $itemId   = $item['item_id'];
                $qty      = $item['qty'];
                $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                // === RAKIT DARI KOMPONEN (opsional) ===
                // Kalau item ini diisi komponen, komponen langsung dikonsumsi (dikurangi dari
                // gudang masing-masing) sebagai BAGIAN dari transaksi packing ini — TIDAK dibuatkan
                // record AssemblingProduction terpisah, supaya tidak ikut kehitung di kolom
                // "Assembling" Dashboard Monitoring (produk ini memang tidak lewat proses Assembling
                // manual). Jejak pemakaian komponennya tetap tercatat di InventoryLog (transaction_type
                // PACKING), cuma atribusinya langsung ke Packing, bukan Assembling.
                if (!empty($item['components'])) {
                    foreach ($item['components'] as $cIndex => $component) {
                        $cItemId      = $component['item_id'];
                        $cWarehouseId = $component['warehouse_id'];
                        $cQty         = $component['qty'];
                        $cFinishing   = $component['finishing'] ?? null;
                        $cItemName    = Item::find($cItemId)?->name ?? "ID {$cItemId}";
                        $cWhName      = Warehouse::find($cWarehouseId)?->name ?? "ID {$cWarehouseId}";

                        $cSourceInv = Inventory::where('item_id', $cItemId)
                            ->where('warehouse_id', $cWarehouseId)
                            ->lockForUpdate()
                            ->first();

                        $cAvailableQty = $cSourceInv?->qty_pcs ?? 0;
                        if ($cAvailableQty < $cQty) {
                            throw ValidationException::withMessages([
                                "items.components.{$cIndex}.qty" => [
                                    "Komponen '{$cItemName}' stok di {$cWhName} tidak cukup. " .
                                    "Tersedia: {$cAvailableQty} pcs, dibutuhkan: {$cQty} pcs."
                                ],
                            ]);
                        }

                        $cInputItem     = Item::lockForUpdate()->find($cItemId);
                        $cSourceInvExtra = [];
                        if ($cInputItem && $cInputItem->type === Item::TYPE_COMPONENT) {
                            if (!$cFinishing) {
                                throw ValidationException::withMessages([
                                    "items.components.{$cIndex}.finishing" => ["Jenis finishing (Natural/Warna) wajib dipilih untuk '{$cItemName}'."],
                                ]);
                            }
                            $bucket = $cFinishing === 'warna' ? 'qty_warna' : 'qty_natural';
                            $availableFinishing = (float) $cInputItem->{$bucket};
                            if ($availableFinishing < $cQty) {
                                $label = $cFinishing === 'warna' ? 'Warna' : 'Natural';
                                throw ValidationException::withMessages([
                                    "items.components.{$cIndex}.finishing" => [
                                        "'{$cItemName}' stok {$label} tidak cukup. Tersedia: {$availableFinishing}, dibutuhkan: {$cQty}."
                                    ],
                                ]);
                            }
                            $cInputItem->{$bucket} = $availableFinishing - $cQty;
                            $cInputItem->stock     = (float) $cInputItem->qty_natural + (float) $cInputItem->qty_warna;
                            $cInputItem->save();

                            $cSourceInvExtra = [$bucket => max(0, (float) $cSourceInv->{$bucket} - $cQty)];
                        }

                        $cSourceInv->decrement('qty_pcs', $cQty, $cSourceInvExtra);

                        InventoryLog::create([
                            'date'             => $data['date'],
                            'time'             => now()->toTimeString(),
                            'item_id'          => $cItemId,
                            'warehouse_id'     => $cWarehouseId,
                            'qty'              => $cQty,
                            'qty_m3'           => 0,
                            'direction'        => 'OUT',
                            'transaction_type' => 'PACKING',
                            'reference_type'   => 'ProductionOrder',
                            'reference_id'     => $data['ref_po_id'],
                            'reference_number' => $documentNumber,
                            'notes'            => "Komponen dipakai untuk merakit & mengemas '{$itemName}' ({$documentNumber}) - PO: {$poNumber}",
                            'user_id'          => Auth::id(),
                        ]);
                    }

                    Log::info("Rakit dari komponen (packing): {$itemName} - {$qty} pcs dari " . count($item['components']) . ' komponen');
                } else {
                    // === ALUR BIASA: KURANGI STOK GUDANG SUMBER ===
                    $sourceInv = Inventory::where('item_id', $itemId)
                        ->where('warehouse_id', $sourceWarehouse->id)
                        ->lockForUpdate()
                        ->first();

                    $availableQty = $sourceInv?->qty_pcs ?? 0;
                    if ($availableQty < $qty) {
                        throw ValidationException::withMessages([
                            'items' => [
                                "Stok '{$itemName}' di gudang {$sourceWarehouse->name} tidak cukup. " .
                                "Tersedia: {$availableQty} pcs, diminta: {$qty} pcs."
                            ],
                        ]);
                    }

                    $sourceInv->decrement('qty_pcs', $qty);

                    InventoryLog::create([
                        'date'             => $data['date'],
                        'time'             => now()->toTimeString(),
                        'item_id'          => $itemId,
                        'warehouse_id'     => $sourceWarehouse->id,
                        'qty'              => $qty,
                        'qty_m3'           => 0,
                        'direction'        => 'OUT',
                        'transaction_type' => 'PACKING',
                        'reference_type'   => 'ProductionOrder',
                        'reference_id'     => $data['ref_po_id'],
                        'reference_number' => $documentNumber,
                        'notes'            => "Keluar dari {$sourceWarehouse->name} untuk dikemas ({$documentNumber}) - PO: {$poNumber}",
                        'user_id'          => Auth::id(),
                    ]);
                }

                // === TAMBAH STOK GUDANG PACKING ===
                $packingInv = Inventory::where('item_id', $itemId)
                    ->where('warehouse_id', $warehousePacking->id)
                    ->lockForUpdate()
                    ->first();

                if ($packingInv) {
                    $packingInv->increment('qty_pcs', $qty);
                } else {
                    Inventory::create([
                        'item_id'      => $itemId,
                        'warehouse_id' => $warehousePacking->id,
                        'qty_pcs'      => $qty,
                        'ref_po_id'    => $data['ref_po_id'],
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $itemId,
                    'warehouse_id'     => $warehousePacking->id,
                    'qty'              => $qty,
                    'qty_m3'           => 0,
                    'direction'        => 'IN',
                    'transaction_type' => 'PACKING',
                    'reference_type'   => 'ProductionOrder',
                    'reference_id'     => $data['ref_po_id'],
                    'reference_number' => $documentNumber,
                    'notes'            => "Produk jadi dikemas ({$documentNumber}) - PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                $sourceLabel = !empty($item['components']) ? 'Rakit Komponen' : $sourceWarehouse->name;
                Log::info("Packing: {$itemName} - {$qty} pcs | {$sourceLabel} → Gudang Packing");
            }

            // Update stage PO
            if ($productionOrder) {
                $productionOrder->current_stage = 'packing';
                $productionOrder->status        = 'in_progress';
                $productionOrder->save();
            }

            Log::info('=== PACKING END ===', ['doc' => $documentNumber]);

            return response()->json([
                'success' => true,
                'message' => "Packing berhasil dicatat ({$documentNumber})",
                'data'    => [
                    'document_number' => $documentNumber,
                    'total_items'     => count($data['items']),
                ],
            ], 201);
        });
    }

    // =============================================
    // POST: Selesai Packing → PO completed
    // =============================================
    public function selesaiPacking(Request $request, $poId)
    {
        $productionOrder = ProductionOrder::findOrFail($poId);

        if ($productionOrder->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'PO ini sudah selesai sebelumnya.',
            ], 422);
        }

        $productionOrder->update([
            'current_stage' => 'completed',
            'status'        => 'completed',
        ]);

        Log::info('=== PACKING SELESAI ===', [
            'po_id'     => $poId,
            'po_number' => $productionOrder->po_number,
        ]);

        return response()->json([
            'success' => true,
            'message' => "PO {$productionOrder->po_number} selesai! Produk jadi siap dikirim.",
        ]);
    }
}