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

    // =============================================
    // GET: Semua item Kayu RST dari master
    // =============================================
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
            ->map(function ($item) {
                return [
                    'id'             => $item->id,
                    'code'           => $item->code,
                    'name'           => $item->name,
                    'specifications' => $item->specifications,
                    'volume_m3'      => $item->volume_m3,
                ];
            });

        return response()->json(['success' => true, 'data' => $items]);
    }

    // =============================================
    // GET: Semua item Komponen dari master
    // =============================================
    public function getKomponenItems(Request $request)
    {
        // Ambil semua kategori yang bukan Kayu RST, Kayu Log, Produk Jadi
        $excludeCategories = ['Kayu RST', 'Kayu Log', 'Produk Jadi'];

        $items = Item::whereHas('category', function ($q) use ($excludeCategories) {
                $q->whereNotIn('name', $excludeCategories);
            })
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get()
            ->map(function ($item) {
                return [
                    'id'       => $item->id,
                    'code'     => $item->code,
                    'name'     => $item->name,
                    'category' => $item->category?->name,
                ];
            });

        return response()->json(['success' => true, 'data' => $items]);
    }

    // =============================================
    // GET: Available POs untuk dropdown
    // =============================================
    public function getAvailablePos(Request $request)
    {
        $pos = ProductionOrder::where('status', '!=', 'completed')
            ->with(['salesOrder.buyer'])
            ->get()
            ->map(function ($po) {
                return [
                    'id'         => $po->id,
                    'po_number'  => $po->po_number,
                    'label'      => $po->po_number,
                    'buyer_name' => $po->salesOrder?->buyer?->name ?? '-',
                    'so_number'  => $po->salesOrder?->so_number ?? '-',
                ];
            });

        return response()->json(['success' => true, 'data' => $pos]);
    }

    // =============================================
    // POST: Simpan proses moulding
    // =============================================
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'       => ['required', 'date'],
            'ref_po_id'  => ['required', 'integer', 'exists:production_orders,id'],
            'notes'      => ['nullable', 'string'],

            // Input: RST yang dipakai
            'inputs'            => ['required', 'array', 'min:1'],
            'inputs.*.item_id'  => ['required', 'integer', 'exists:items,id'],
            'inputs.*.qty'      => ['required', 'numeric', 'min:0.01'],

            // Output: Komponen yang dihasilkan
            'outputs'           => ['required', 'array', 'min:1'],
            'outputs.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'outputs.*.qty'     => ['required', 'numeric', 'min:1'],

            // Reject: opsional
            'rejects'                => ['nullable', 'array'],
            'rejects.*.item_id'      => ['required_with:rejects', 'integer', 'exists:items,id'],
            'rejects.*.qty'          => ['required_with:rejects', 'numeric', 'min:0.01'],
            'rejects.*.reject_type'  => ['required_with:rejects', 'in:moulding,pembahanan'],
            'rejects.*.keterangan'   => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data) {
            Log::info('=== MOULDING START ===', ['po_id' => $data['ref_po_id']]);

            // === GUDANG ===
            $warehouseS4S = Warehouse::where('code', 'S4S')->first();
            $warehouseReject = Warehouse::where('code', 'REJECT')->first();

            if (!$warehouseS4S) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Moulding S4S tidak ditemukan di master.'],
                ]);
            }
            if (!$warehouseReject) {
                throw ValidationException::withMessages([
                    'warehouse' => ['Gudang Reject (REJECT) tidak ditemukan di master.'],
                ]);
            }

            $productionOrder = ProductionOrder::find($data['ref_po_id']);
            $poNumber        = $productionOrder?->po_number ?? '-';

            // === NOMOR DOKUMEN ===
            $runningNumber  = MouldingProduction::whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->count() + 1;
            $documentNumber = 'MLD-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            // === SIMPAN HEADER ===
            $moulding = MouldingProduction::create([
                'document_number' => $documentNumber,
                'date'            => $data['date'],
                'ref_po_id'       => $data['ref_po_id'],
                'notes'           => $data['notes'] ?? null,
                'created_by'      => Auth::id(),
            ]);

            // === PROSES INPUT (RST yang dipakai) ===
            // Catatan: RST tidak dikurangi dari gudang tertentu
            // karena input diambil dari master Kayu RST
            // yang penting tercatat untuk laporan
            foreach ($data['inputs'] as $input) {
                MouldingProductionInput::create([
                    'moulding_production_id' => $moulding->id,
                    'item_id'                => $input['item_id'],
                    'qty'                    => $input['qty'],
                ]);

                // Catat inventory log OUT dari Gudang Pembahanan
                $bufferWarehouse = Warehouse::where('code', 'BUFFER')->first();
                if ($bufferWarehouse) {
                    $inv = Inventory::where('item_id', $input['item_id'])
                        ->where('warehouse_id', $bufferWarehouse->id)
                        ->lockForUpdate()
                        ->first();

                    if ($inv && $inv->qty_pcs >= $input['qty']) {
                        $inv->decrement('qty_pcs', $input['qty']);
                    }

                    InventoryLog::create([
                        'date'             => $data['date'],
                        'time'             => now()->toTimeString(),
                        'item_id'          => $input['item_id'],
                        'warehouse_id'     => $bufferWarehouse->id,
                        'qty'              => $input['qty'],
                        'qty_m3'           => 0,
                        'direction'        => 'OUT',
                        'transaction_type' => 'MOULDING',
                        'reference_type'   => 'MouldingProduction',
                        'reference_id'     => $moulding->id,
                        'reference_number' => $documentNumber,
                        'notes'            => "RST dipakai proses moulding ({$documentNumber})",
                        'user_id'          => Auth::id(),
                    ]);
                }

                Log::info("Input RST: item_id={$input['item_id']}, qty={$input['qty']}");
            }

            // === PROSES OUTPUT (Komponen) → Gudang S4S ===
            foreach ($data['outputs'] as $output) {
                MouldingProductionOutput::create([
                    'moulding_production_id' => $moulding->id,
                    'item_id'                => $output['item_id'],
                    'qty'                    => $output['qty'],
                ]);

                // Tambah stok di Gudang S4S
                $inv = Inventory::where('item_id', $output['item_id'])
                    ->where('warehouse_id', $warehouseS4S->id)
                    ->lockForUpdate()
                    ->first();

                if ($inv) {
                    $inv->increment('qty_pcs', $output['qty']);
                } else {
                    Inventory::create([
                        'item_id'      => $output['item_id'],
                        'warehouse_id' => $warehouseS4S->id,
                        'qty_pcs'      => $output['qty'],
                        'ref_po_id'    => $data['ref_po_id'],
                    ]);
                }

                InventoryLog::create([
                    'date'             => $data['date'],
                    'time'             => now()->toTimeString(),
                    'item_id'          => $output['item_id'],
                    'warehouse_id'     => $warehouseS4S->id,
                    'qty'              => $output['qty'],
                    'qty_m3'           => 0,
                    'direction'        => 'IN',
                    'transaction_type' => 'MOULDING',
                    'reference_type'   => 'MouldingProduction',
                    'reference_id'     => $moulding->id,
                    'reference_number' => $documentNumber,
                    'notes'            => "Hasil moulding masuk Gudang S4S ({$documentNumber}) - PO: {$poNumber}",
                    'user_id'          => Auth::id(),
                ]);

                Log::info("Output Komponen: item_id={$output['item_id']}, qty={$output['qty']}");
            }

            // === PROSES REJECT → Gudang Reject ===
            if (!empty($data['rejects'])) {
                foreach ($data['rejects'] as $reject) {
                    if (empty($reject['item_id']) || empty($reject['qty'])) continue;

                    MouldingProductionReject::create([
                        'moulding_production_id' => $moulding->id,
                        'item_id'                => $reject['item_id'],
                        'qty'                    => $reject['qty'],
                        'reject_type'            => $reject['reject_type'] ?? 'moulding',
                        'keterangan'             => $reject['keterangan'] ?? null,
                    ]);

                    // Tambah stok di Gudang Reject
                    $inv = Inventory::where('item_id', $reject['item_id'])
                        ->where('warehouse_id', $warehouseReject->id)
                        ->lockForUpdate()
                        ->first();

                    if ($inv) {
                        $inv->increment('qty_pcs', $reject['qty']);
                    } else {
                        Inventory::create([
                            'item_id'      => $reject['item_id'],
                            'warehouse_id' => $warehouseReject->id,
                            'qty_pcs'      => $reject['qty'],
                        ]);
                    }

                    InventoryLog::create([
                        'date'             => $data['date'],
                        'time'             => now()->toTimeString(),
                        'item_id'          => $reject['item_id'],
                        'warehouse_id'     => $warehouseReject->id,
                        'qty'              => $reject['qty'],
                        'qty_m3'           => 0,
                        'direction'        => 'IN',
                        'transaction_type' => 'REJECT',
                        'reference_type'   => 'MouldingProduction',
                        'reference_id'     => $moulding->id,
                        'reference_number' => $documentNumber,
                        'notes'            => "Reject {$reject['reject_type']} - {$reject['keterangan']} ({$documentNumber})",
                        'user_id'          => Auth::id(),
                    ]);

                    Log::info("Reject: item_id={$reject['item_id']}, qty={$reject['qty']}, type={$reject['reject_type']}");
                }
            }

            // === UPDATE STAGE PO ===
            if ($productionOrder) {
                $productionOrder->current_stage = 'moulding';
                $productionOrder->status        = 'in_progress';
                $productionOrder->save();
            }

            Log::info('=== MOULDING END ===', ['doc' => $documentNumber]);

            return response()->json([
                'success' => true,
                'message' => "Proses Moulding berhasil ({$documentNumber})",
                'data'    => [
                    'id'              => $moulding->id,
                    'document_number' => $documentNumber,
                    'total_inputs'    => count($data['inputs']),
                    'total_outputs'   => count($data['outputs']),
                    'total_rejects'   => count($data['rejects'] ?? []),
                ],
            ], 201);
        });
    }

    public function tandaiSelesai(Request $request, $id)
    {
        $productionOrder = ProductionOrder::findOrFail($id);

        $productionOrder->update([
            'current_stage' => 'mesin',
            'status'        => 'in_progress',
        ]);

        return response()->json([
            'success' => true,
            'message' => "PO {$productionOrder->po_number} selesai moulding, lanjut ke proses Mesin.",
        ]);
    }
}