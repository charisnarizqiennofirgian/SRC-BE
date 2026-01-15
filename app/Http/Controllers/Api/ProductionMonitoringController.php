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
     * - Zona Hulu: Status (Ada aktivitas atau tidak)
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

            // ID Gudang HULU (Status: ada aktivitas atau tidak)
            $warehouseHulu = [
                'sanwil' => 2,
                'kd' => 3,
                'pembahanan' => 4,
                'moulding' => 5,
                'mesin' => 6,
            ];

            // ID Gudang HILIR (Angka: SUM qty)
            $warehouseHilir = [
                'assembling' => 7,
                'rustik' => 8,
                'sanding' => 9,
                'finishing' => 10,
                'packing' => 11,
            ];

            $result = [];

            foreach ($salesOrders as $so) {
                // Ambil semua Production Order IDs yang terkait dengan SO ini
                $poIds = $so->productionOrders->pluck('id')->toArray();

                foreach ($so->details as $detail) {
                    // === ZONA HULU: Cek ada aktivitas atau tidak ===
                    $statusHulu = [];

                    foreach ($warehouseHulu as $key => $warehouseId) {
                        $hasActivity = false;

                        if (!empty($poIds)) {
                            $count = InventoryLog::where('reference_type', 'ProductionOrder')
                                ->whereIn('reference_id', $poIds)
                                ->where('item_id', $detail->item_id)
                                ->where('warehouse_id', $warehouseId)
                                ->count();

                            $hasActivity = $count > 0;
                        }

                        $statusHulu[$key] = $hasActivity;
                    }

                    // === ZONA HILIR: Hitung SUM qty ===
                    $qtyHilir = [];

                    foreach ($warehouseHilir as $key => $warehouseId) {
                        $qtyIn = 0;

                        if (!empty($poIds)) {
                            $qtyIn = InventoryLog::where('reference_type', 'ProductionOrder')
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

                        // Zona Hulu (Status boolean)
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
