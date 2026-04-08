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
    // POST: Simpan proses packing
    // Output: stok Gudang Packing NAMBAH
    // =============================================
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'            => ['required', 'date'],
            'ref_po_id'       => ['required', 'integer', 'exists:production_orders,id'],
            'notes'           => ['nullable', 'string'],
            'items'           => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty'     => ['required', 'numeric', 'min:1'],
        ]);

        return DB::transaction(function () use ($data) {
            Log::info('=== PACKING START ===', ['po_id' => $data['ref_po_id']]);

            $warehousePacking = Warehouse::where('code', 'PACKING')->first();

            if (!$warehousePacking) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Packing tidak ditemukan.'],
                ]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';

            // === NOMOR DOKUMEN ===
            $runningNumber  = InventoryLog::where('transaction_type', 'PACKING')
                ->whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->where('direction', 'IN')
                ->count() + 1;
            $documentNumber = 'PKG-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            foreach ($data['items'] as $item) {
                $itemId   = $item['item_id'];
                $qty      = $item['qty'];
                $itemName = Item::find($itemId)?->name ?? "ID {$itemId}";

                // Tambah stok di Gudang Packing (produk jadi)
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

                Log::info("Packing: {$itemName} - {$qty} pcs masuk Gudang Packing");
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