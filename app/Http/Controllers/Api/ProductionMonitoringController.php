<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\InventoryLog;
use Illuminate\Http\Request;

class ProductionMonitoringController extends Controller
{
    /**
     * Dashboard Monitoring Produksi per SO (Hybrid View)
     * - Zona Hulu: Status 3 level (WAITING/IN_PROGRESS/DONE)
     * - Zona Hilir: Angka real (SUM qty)
     */
    public function index(Request $request)
    {
        try {
            // Ambil SO yang sudah Confirmed (bukan Draft)
            $query = SalesOrder::with(['buyer', 'details.item', 'productionOrders'])
                ->where('status', '!=', 'Draft')
                ->orderBy('created_at', 'desc');

            // Filter by SO number atau Buyer name
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('so_number', 'LIKE', "%{$search}%")
                      ->orWhereHas('buyer', function ($bq) use ($search) {
                          $bq->where('name', 'LIKE', "%{$search}%");
                      });
                });
            }

            // Limit untuk dashboard widget
            if ($request->filled('limit')) {
                $query->limit($request->limit);
            }

            $salesOrders = $query->get();

            // ID Gudang HULU (Status: WAITING / IN_PROGRESS / DONE)
            $warehouseHulu = [
                'sanwil' => 2,      // Gudang Sanwil (RST Basah)
                'kd' => 3,          // Gudang KD (RST Kering)
                'pembahanan' => 4,  // Gudang Pembahanan (Buffer RST)
                'moulding' => 5,    // Gudang Moulding (S4S)
                'mesin' => 6,       // Gudang Mesin (Komponen)
            ];

            // ID Gudang HILIR (Angka: SUM qty)
            $warehouseHilir = [
                'assembling' => 7,
                'rustik' => 8,
                'sanding' => 9,
                'finishing' => 10,
                'packing' => 11,
            ];

            // ✅ FIX: Daftar reference_type yang valid untuk tracking produksi
            $validReferenceTypes = [
                'ProductionOrder',
                'KDProduction',      // Tambahan untuk proses KD/Candy
                'CandyProduction',   // Backward compatibility jika ada data lama
            ];

            $result = [];

            foreach ($salesOrders as $so) {
                // Ambil semua Production Order IDs yang terkait dengan SO ini
                $poIds = $so->productionOrders->pluck('id')->toArray();

                foreach ($so->details as $detail) {
                    // === ZONA HULU: Cek status 3 level (by PO ID saja) ===
                    $statusHulu = [];

                    foreach ($warehouseHulu as $key => $warehouseId) {
                        $status = 'waiting'; // Default: belum ada aktivitas

                        if (!empty($poIds)) {
                            // ✅ FIX: Include semua reference_type yang valid
                            // Hitung total IN ke gudang ini
                            $qtyIn = InventoryLog::whereIn('reference_type', $validReferenceTypes)
                                ->whereIn('reference_id', $poIds)
                                ->where('warehouse_id', $warehouseId)
                                ->where('direction', 'IN')
                                ->sum('qty');

                            // Hitung total OUT dari gudang ini
                            $qtyOut = InventoryLog::whereIn('reference_type', $validReferenceTypes)
                                ->whereIn('reference_id', $poIds)
                                ->where('warehouse_id', $warehouseId)
                                ->where('direction', 'OUT')
                                ->sum('qty');

                            // Tentukan status
                            if ($qtyIn == 0) {
                                $status = 'waiting';      // 🔴 Belum ada aktivitas
                            } elseif ($qtyIn > $qtyOut) {
                                $status = 'in_progress';  // 🟡 Ada sisa, belum semua keluar
                            } else {
                                $status = 'done';         // ✅ Semua sudah keluar
                            }
                        }

                        $statusHulu[$key] = $status;
                    }

                    // === ZONA HILIR: Hitung SUM qty (by PO ID + item_id) ===
                    $qtyHilir = [];

                    foreach ($warehouseHilir as $key => $warehouseId) {
                        $qtyIn = 0;

                        if (!empty($poIds)) {
                            // ✅ FIX: Include semua reference_type yang valid
                            $qtyIn = InventoryLog::whereIn('reference_type', $validReferenceTypes)
                                ->whereIn('reference_id', $poIds)
                                ->where('item_id', $detail->item_id)
                                ->where('warehouse_id', $warehouseId)
                                ->where('direction', 'IN')
                                ->sum('qty');
                        }

                        $qtyHilir[$key] = (float) $qtyIn;
                    }

                    // Target dari sales_order_details
                    $target = (float) $detail->quantity;
                    $qtyPacking = $qtyHilir['packing'];
                    $sisa = $target - $qtyPacking;

                    $result[] = [
                        'so_id' => $so->id,
                        'so_number' => $so->so_number,
                        'so_date' => $so->so_date ? $so->so_date->format('d/m/Y') : '-',
                        'status' => $so->status,
                        'buyer_name' => $so->buyer->name ?? '-',
                        'item_id' => $detail->item_id,
                        'item_name' => $detail->item->name ?? '-',
                        'item_code' => $detail->item->code ?? '-',
                        'target' => $target,

                        // Zona Hulu (Status: waiting / in_progress / done)
                        'status_sanwil' => $statusHulu['sanwil'],
                        'status_kd' => $statusHulu['kd'],
                        'status_pembahanan' => $statusHulu['pembahanan'],
                        'status_moulding' => $statusHulu['moulding'],
                        'status_mesin' => $statusHulu['mesin'],

                        // Zona Hilir (Angka)
                        'qty_assembling' => $qtyHilir['assembling'],
                        'qty_rustik' => $qtyHilir['rustik'],
                        'qty_sanding' => $qtyHilir['sanding'],
                        'qty_finishing' => $qtyHilir['finishing'],
                        'qty_packing' => $qtyPacking,

                        'sisa' => $sisa,
                        'is_done' => $sisa <= 0,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $result,
                'total_so' => $salesOrders->count(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage(),
            ], 500);
        }
    }
}
