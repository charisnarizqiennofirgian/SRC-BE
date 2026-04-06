<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Warehouse;
use App\Models\SalesOrder;
use App\Models\InventoryLog;
use Illuminate\Http\Request;

class ProductionMonitoringController extends Controller
{
    public function index(Request $request)
    {
        try {
            // === AMBIL WAREHOUSE ID DINAMIS (tidak hardcode) ===
            $warehouses = Warehouse::whereIn('code', [
                'RUSKOMP', 'ASSEMBLING', 'SANDING', 'RUSTIK',
                'FINISHING', 'PACKING'
            ])->pluck('id', 'code');

            // Zona Hilir — tampilkan angka qty
            $warehouseHilir = [
                'ruskomp'   => $warehouses['RUSKOMP']   ?? null,
                'assembling'=> $warehouses['ASSEMBLING']?? null,
                'sanding'   => $warehouses['SANDING']   ?? null,
                'rustik'    => $warehouses['RUSTIK']    ?? null,
                'finishing' => $warehouses['FINISHING'] ?? null,
                'packing'   => $warehouses['PACKING']   ?? null,
            ];

            // === AMBIL SO ===
            $query = SalesOrder::with(['buyer', 'details.item', 'productionOrders'])
                ->where('status', '!=', 'Draft')
                ->orderBy('created_at', 'desc');

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('so_number', 'LIKE', "%{$search}%")
                      ->orWhereHas('buyer', function ($bq) use ($search) {
                          $bq->where('name', 'LIKE', "%{$search}%");
                      });
                });
            }

            if ($request->filled('limit')) {
                $query->limit($request->limit);
            }

            $salesOrders = $query->get();

            $result = [];

            foreach ($salesOrders as $so) {
                $poIds = $so->productionOrders->pluck('id')->toArray();

                foreach ($so->details as $detail) {
                    $itemId = $detail->item_id;

                    // === ZONA HULU: Status per stage berdasarkan PO ===
                    $statusHulu = [];

                    $stageTypes = [
                        'sanwil'     => ['SAWMILL'],
                        'kd'         => ['KD', 'CANDY'],
                        'pembahanan' => ['PEMBAHANAN'],
                        'moulding'   => ['MOULDING'],
                        'mesin'      => ['MESIN'],
                    ];

                    foreach ($stageTypes as $key => $types) {
                        if (empty($poIds)) {
                            $statusHulu[$key] = 'waiting';
                            continue;
                        }

                        // Cek apakah PO pernah ada transaksi IN di stage ini
                        $totalIn = InventoryLog::whereIn('transaction_type', $types)
                            ->whereIn('reference_id', $poIds)
                            ->where('direction', 'IN')
                            ->sum('qty');

                        // Cek total OUT
                        $totalOut = InventoryLog::whereIn('transaction_type', $types)
                            ->whereIn('reference_id', $poIds)
                            ->where('direction', 'OUT')
                            ->sum('qty');

                        if ($totalIn == 0) {
                            $statusHulu[$key] = 'waiting';      // 🔴 Belum ada aktivitas
                        } elseif ($totalIn > $totalOut) {
                            $statusHulu[$key] = 'in_progress';  // 🟡 Ada sisa
                        } else {
                            $statusHulu[$key] = 'done';         // ✅ Semua sudah keluar
                        }
                    }

                    // === ZONA HILIR: Qty per gudang ===
                    $qtyHilir = [];

                    foreach ($warehouseHilir as $key => $warehouseId) {
                        if (!$warehouseId) {
                            $qtyHilir[$key] = 0;
                            continue;
                        }

                        // Ambil stok saat ini di gudang
                        $qty = Inventory::where('warehouse_id', $warehouseId)
                            ->where('item_id', $itemId)
                            ->sum('qty_pcs');

                        $qtyHilir[$key] = (float) $qty;
                    }

                    $target    = (float) $detail->quantity;

                    // Hitung qty QC Final dari inventory_log
                    $qtyQcFinal = InventoryLog::where('transaction_type', 'QC_FINAL')
                        ->where('item_id', $itemId)
                        ->where('direction', 'IN')
                        ->when(!empty($poIds), function ($q) use ($poIds) {
                            $q->whereIn('reference_id', $poIds);
                        })
                        ->sum('qty');

                    $qtyPacking = $qtyHilir['packing'];
                    $sisa      = $target - $qtyPacking;
                    $isDone    = $qtyPacking >= $target && $target > 0;

                    // Cek apakah PO sudah completed
                    $poCompleted = $so->productionOrders
                        ->where('status', 'completed')
                        ->count() > 0;

                    $result[] = [
                        'so_id'              => $so->id,
                        'so_number'          => $so->so_number,
                        'so_date'            => $so->so_date
                            ? \Carbon\Carbon::parse($so->so_date)->format('d/m/Y')
                            : '-',
                        'buyer_name'         => $so->buyer?->name ?? '-',
                        'item_id'            => $itemId,
                        'item_name'          => $detail->item?->name ?? '-',
                        'item_code'          => $detail->item?->code ?? '-',
                        'target'             => $target,
                        'po_completed'       => $poCompleted,

                        // Zona Hulu
                        'status_sanwil'      => $statusHulu['sanwil'],
                        'status_kd'          => $statusHulu['kd'],
                        'status_pembahanan'  => $statusHulu['pembahanan'],
                        'status_moulding'    => $statusHulu['moulding'],
                        'status_mesin'       => $statusHulu['mesin'],

                        // Zona Hilir
                        'qty_ruskomp'        => $qtyHilir['ruskomp'],
                        'qty_assembling'     => $qtyHilir['assembling'],
                        'qty_sanding'        => $qtyHilir['sanding'],
                        'qty_rustik'         => $qtyHilir['rustik'],
                        'qty_finishing'      => $qtyHilir['finishing'],
                        'qty_qc_final'       => (float) $qtyQcFinal,
                        'qty_packing'        => $qtyHilir['packing'],

                        'sisa'               => max(0, $sisa),
                        'is_done'            => $isDone || $poCompleted,
                    ];
                }
            }

            return response()->json([
                'success'  => true,
                'data'     => $result,
                'total_so' => $salesOrders->count(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // =============================================
    // GET: Detail transaksi per SO per stage
    // =============================================
    public function detail(Request $request)
    {
        $request->validate([
            'so_id'   => ['required', 'integer'],
            'item_id' => ['nullable', 'integer'],
        ]);

        try {
            $so    = SalesOrder::with(['buyer', 'productionOrders'])->findOrFail($request->so_id);
            $poIds = $so->productionOrders->pluck('id')->toArray();

            if (empty($poIds)) {
                return response()->json([
                    'success'    => true,
                    'so_number'  => $so->so_number,
                    'buyer_name' => $so->buyer?->name ?? '-',
                    'stages'     => [],
                    'rejects'    => [],
                ]);
            }

            // Semua stage yang perlu ditampilkan
            $stages = [
                'SAWMILL'        => 'Sawmill',
                'PEMBAHANAN'     => 'Pembahanan',
                'MOULDING'       => 'Moulding',
                'MESIN'          => 'Mesin',
                'RUSTIK_KOMPONEN'=> 'Rustik Komponen',
                'SUB_ASSEMBLING' => 'Sub Assembling',
                'RAKIT'          => 'Rakit',
                'SANDING'        => 'Sanding',
                'RUSTIK'         => 'Rustik',
                'FINISHING'      => 'Finishing',
                'QC_FINAL'       => 'QC Final',
                'PACKING'        => 'Packing',
            ];

            $result = [];

            foreach ($stages as $type => $label) {
                $logsIn = InventoryLog::whereIn('transaction_type', [$type])
                    ->whereIn('reference_id', $poIds)
                    ->where('direction', 'IN')
                    ->with(['warehouse', 'item', 'user'])
                    ->orderBy('date', 'asc')
                    ->get();

                $logsOut = InventoryLog::whereIn('transaction_type', [$type])
                    ->whereIn('reference_id', $poIds)
                    ->where('direction', 'OUT')
                    ->with(['warehouse', 'item', 'user'])
                    ->orderBy('date', 'asc')
                    ->get();

                if ($logsIn->isEmpty() && $logsOut->isEmpty()) continue;

                $mapLog = function ($log) {
                    return [
                        'date'             => \Carbon\Carbon::parse($log->date)->format('d/m/Y'),
                        'time'             => $log->time,
                        'item_name'        => $log->item?->name ?? '-',
                        'item_code'        => $log->item?->code ?? '-',
                        'warehouse_name'   => $log->warehouse?->name ?? '-',
                        'qty'              => (float) $log->qty,
                        'notes'            => $log->notes ?? '-',
                        'user_name'        => $log->user?->name ?? '-',
                        'reference_number' => $log->reference_number ?? '-',
                    ];
                };

                $result[] = [
                    'stage'     => $label,
                    'type'      => $type,
                    'total_in'  => (float) $logsIn->sum('qty'),
                    'total_out' => (float) $logsOut->sum('qty'),
                    'inputs'    => $logsIn->map($mapLog),
                    'outputs'   => $logsOut->map($mapLog),
                ];
            }

            // Reject
            $rejectLogs = InventoryLog::where('transaction_type', 'LIKE', '%REJECT%')
                ->whereIn('reference_id', $poIds)
                ->where('direction', 'IN')
                ->with(['warehouse', 'item', 'user'])
                ->orderBy('date', 'asc')
                ->get();

            $rejects = $rejectLogs->map(function ($log) {
                return [
                    'date'             => $log->date,
                    'item_name'        => $log->item?->name ?? '-',
                    'item_code'        => $log->item?->code ?? '-',
                    'qty'              => (float) $log->qty,
                    'transaction_type' => $log->transaction_type,
                    'notes'            => $log->notes ?? '-',
                    'reference_number' => $log->reference_number ?? '-',
                    'user_name'        => $log->user?->name ?? '-',
                ];
            })->toArray();

            return response()->json([
                'success'    => true,
                'so_number'  => $so->so_number,
                'buyer_name' => $so->buyer?->name ?? '-',
                'po_numbers' => $so->productionOrders->pluck('po_number'),
                'stages'     => $result,
                'rejects'    => $rejects,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}