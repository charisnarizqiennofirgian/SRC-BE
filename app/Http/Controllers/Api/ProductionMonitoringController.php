<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\InventoryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProductionMonitoringController extends Controller
{
    // Sub-tabel map — dipakai di index, detail, dan exportExcel
    private array $subTableMap = [
        'KD'              => 'kd_productions',
        'PEMBAHANAN'      => 'pembahanan_productions',
        'MOULDING'        => 'moulding_productions',
        'MESIN'           => 'mesin_productions',
        'RUSTIK_KOMPONEN' => 'rustik_komponen_productions',
        'SUB_ASSEMBLING'  => 'assembling_productions',
        'RAKIT'           => 'assembling_productions',
        'QC_FINAL'        => 'qc_final_productions',
    ];

    private function getSearchIds(string $txType, array $poIds): array
    {
        $searchIds = $poIds;
        if (isset($this->subTableMap[$txType]) && !empty($poIds)) {
            $subIds = DB::table($this->subTableMap[$txType])
                ->whereIn('ref_po_id', $poIds)
                ->pluck('id')
                ->toArray();
            $searchIds = array_merge($poIds, $subIds);
        }
        return $searchIds;
    }

    // Kumpulkan semua ID produksi dari semua sub-tabel untuk query reject
    private function getAllProductionIds(array $poIds): array
    {
        if (empty($poIds)) return [];

        $allIds = $poIds;
        $uniqueTables = array_unique(array_values($this->subTableMap));

        foreach ($uniqueTables as $table) {
            $subIds = DB::table($table)
                ->whereIn('ref_po_id', $poIds)
                ->pluck('id')
                ->toArray();
            $allIds = array_merge($allIds, $subIds);
        }

        return array_unique($allIds);
    }

    // Zona hilir — tiap key gudang bisa dipetakan ke >1 transaction_type (mis. assembling: sub_assembling & rakit)
    private array $hilirStageTypes = [
        'ruskomp'    => ['RUSTIK_KOMPONEN'],
        'assembling' => ['SUB_ASSEMBLING', 'RAKIT'],
        'sanding'    => ['SANDING'],
        'rustik'     => ['RUSTIK'],
        'finishing'  => ['FINISHING'],
        'packing'    => ['PACKING'],
    ];

    public function index(Request $request)
    {
        try {
            $query = SalesOrder::with([
                    'buyer',
                    'details.item',
                    'productionOrders' => fn($q) => $q->where('type', 'production')->with('details'),
                ])
                ->where('status', '!=', 'Draft')
                ->whereHas('productionOrders', fn($q) => $q->where('type', 'production'))
                ->orderBy('created_at', 'desc');

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('so_number', 'LIKE', "%{$search}%")
                      ->orWhereHas('buyer', fn($bq) => $bq->where('name', 'LIKE', "%{$search}%"));
                });
            }

            if ($request->filled('limit')) {
                $query->limit($request->limit);
            }

            $salesOrders = $query->get();
            $result = [];

            foreach ($salesOrders as $so) {
                $poIds = $so->productionOrders->pluck('id')->toArray();

                // Semua detail dari semua PO milik SO ini (untuk cek moulding per item)
                $allPoDetails = $so->productionOrders->flatMap(fn($po) => $po->details);

                // Search IDs zona hilir, di-scope ke PO milik SO ini saja (bukan seluruh gudang)
                $hilirSearchIds = [];
                foreach ($this->hilirStageTypes as $txTypes) {
                    foreach ($txTypes as $txType) {
                        $hilirSearchIds[$txType] = $this->getSearchIds($txType, $poIds);
                    }
                }

                $items = [];

                foreach ($so->details as $detail) {
                    // Item yang sudah terkirim penuh (quantity_shipped >= quantity) tidak perlu
                    // diprioritaskan lagi di dashboard produksi — sudah tidak ada kerjaan produksi
                    // yang tersisa untuk item ini, terlepas dari status current_stage/PO.
                    $qtyOrderedCheck = (float) $detail->quantity;
                    $qtyShippedCheck = (float) ($detail->quantity_shipped ?? 0);
                    if ($qtyOrderedCheck > 0 && $qtyShippedCheck >= $qtyOrderedCheck) {
                        continue;
                    }

                    $itemId = $detail->item_id;

                    // === ZONA HULU ===
                    $stageTypes = [
                        'sanwil'     => 'SAWMILL',
                        'kd'         => 'KD',
                        'pembahanan' => 'PEMBAHANAN',
                        'moulding'   => 'MOULDING',
                        'mesin'      => 'MESIN',
                    ];

                    $statusHulu = [];

                    // === QTY MOULDING & MESIN (per item, dari production_order_detail) ===
                    // Sumber angka ini adalah `qty_produk_jadi` yang diisi MANUAL oleh operator
                    // di header transaksi Moulding/Mesin (bukan lagi qty komponen mentah dari
                    // moulding_production_outputs/mesin_production_outputs) — operator langsung
                    // menyatakan "batch ini setara N unit produk jadi", mirip pola output di Assembling.
                    $detailIds = $allPoDetails->where('item_id', $itemId)->pluck('id')->toArray();

                    $qtyMoulding = 0;
                    if (!empty($detailIds)) {
                        $qtyMoulding = (float) DB::table('moulding_productions')
                            ->whereIn('production_order_detail_id', $detailIds)
                            ->sum('qty_produk_jadi');
                    }

                    $qtyMesin = 0;
                    if (!empty($detailIds)) {
                        $qtyMesin = (float) DB::table('mesin_productions')
                            ->whereIn('production_order_detail_id', $detailIds)
                            ->sum('qty_produk_jadi');
                    }

                    if (empty($poIds)) {
                        foreach (['sanwil', 'kd', 'pembahanan'] as $key) {
                            $statusHulu[$key] = 'waiting';
                        }
                    } else {
                        $colorStages = [
                            'sanwil'     => 'SAWMILL',
                            'kd'         => 'KD',
                            'pembahanan' => 'PEMBAHANAN',
                        ];
                        $activityCache = [];
                        foreach ($colorStages as $key => $txType) {
                            $searchIds          = $this->getSearchIds($txType, $poIds);
                            $activityCache[$key] = InventoryLog::where('transaction_type', $txType)
                                ->whereIn('reference_id', $searchIds)->exists();
                        }

                        $stageOrder = array_keys($colorStages);
                        foreach ($colorStages as $key => $txType) {
                            $hasActivity = $activityCache[$key];
                            $currentIdx  = array_search($key, $stageOrder);
                            $anyLaterActive = false;
                            for ($i = $currentIdx + 1; $i < count($stageOrder); $i++) {
                                if ($activityCache[$stageOrder[$i]]) { $anyLaterActive = true; break; }
                            }
                            if ($anyLaterActive && !$hasActivity)     $statusHulu[$key] = 'skip';
                            elseif ($anyLaterActive && $hasActivity)  $statusHulu[$key] = 'done';
                            elseif ($hasActivity)                     $statusHulu[$key] = 'in_progress';
                            else                                      $statusHulu[$key] = 'waiting';
                        }
                    }

                    // === ZONA HILIR ===
                    // Di-scope ke reference_id milik PO SO ini (via $hilirSearchIds), bukan total stok gudang —
                    // total stok gudang dipakai bersama semua SO yang order item yang sama, jadi tidak boleh dipakai di sini.
                    $qtyHilir = [];
                    foreach ($this->hilirStageTypes as $key => $txTypes) {
                        $qty = 0;
                        foreach ($txTypes as $txType) {
                            $searchIds = $hilirSearchIds[$txType];
                            if (empty($searchIds)) continue;
                            $qty += (float) InventoryLog::where('transaction_type', $txType)
                                ->whereIn('reference_id', $searchIds)
                                ->where('direction', 'IN')
                                ->where('item_id', $itemId)
                                ->sum('qty');
                        }
                        $qtyHilir[$key] = $qty;
                    }

                    $target = (float) $detail->quantity;

                    // reference_id InventoryLog QC_FINAL = qc_final_productions.id (bukan po_id
                    // langsung) — harus lewat getSearchIds() sama seperti stage hilir lainnya,
                    // kalau tidak qty selalu 0 walau QC Final sudah pernah disimpan.
                    $qcFinalSearchIds = $this->getSearchIds('QC_FINAL', $poIds);
                    $qtyQcFinal = 0;
                    if (!empty($qcFinalSearchIds)) {
                        $qtyQcFinal = InventoryLog::where('transaction_type', 'QC_FINAL')
                            ->where('item_id', $itemId)
                            ->where('direction', 'IN')
                            ->whereIn('reference_id', $qcFinalSearchIds)
                            ->sum('qty');
                    }

                    $allProductionIds = $this->getAllProductionIds($poIds);
                    $qtyReject = InventoryLog::where('transaction_type', 'LIKE', '%REJECT%')
                        ->when(!empty($allProductionIds), fn($q) => $q->whereIn('reference_id', $allProductionIds))
                        ->where('direction', 'IN')
                        ->sum('qty');

                    $qtyPacking  = $qtyHilir['packing'];
                    $poCompleted = $so->productionOrders->where('status', 'completed')->count() > 0;

                    $items[] = [
                        'detail_id'         => $detail->id,
                        'item_id'           => $itemId,
                        'item_name'         => $detail->item?->name ?? '-',
                        'item_code'         => $detail->item?->code ?? '-',
                        'target'            => $target,
                        'delivery_date'     => $detail->delivery_date
                                                ? Carbon::parse($detail->delivery_date)->format('d/m/Y')
                                                : '-',

                        // Zona Hulu
                        'status_sanwil'     => $statusHulu['sanwil'],
                        'status_kd'         => $statusHulu['kd'],
                        'status_pembahanan' => $statusHulu['pembahanan'],
                        'qty_moulding'      => $qtyMoulding,
                        'qty_mesin'         => $qtyMesin,

                        // Zona Hilir
                        'qty_ruskomp'       => $qtyHilir['ruskomp'],
                        'qty_assembling'    => $qtyHilir['assembling'],
                        'qty_sanding'       => $qtyHilir['sanding'],
                        'qty_rustik'        => $qtyHilir['rustik'],
                        'qty_finishing'     => $qtyHilir['finishing'],
                        'qty_qc_final'      => (float) $qtyQcFinal,
                        'qty_packing'       => $qtyHilir['packing'],
                        'qty_reject'        => (float) $qtyReject,
                        'has_reject'        => $qtyReject > 0,

                        'sisa'              => max(0, $target - $qtyPacking),
                        'is_done'           => ($qtyPacking >= $target && $target > 0) || $poCompleted,
                    ];
                }

                // Semua item SO ini sudah terkirim penuh (difilter di atas) — SO tidak perlu
                // muncul lagi di dashboard prioritas produksi.
                if (empty($items)) {
                    continue;
                }

                // Group per SO
                $result[] = [
                    'so_id'              => $so->id,
                    'so_number'          => $so->so_number,
                    'so_date'            => $so->so_date ? Carbon::parse($so->so_date)->format('d/m/Y') : '-',
                    'buyer_name'         => $so->buyer?->name ?? '-',
                    'customer_po_number' => $so->customer_po_number ?? null,
                    'po_numbers'         => $so->productionOrders->pluck('po_number'),
                    'is_done'            => $so->productionOrders->where('status', 'completed')->count() > 0,
                    'items'              => $items,
                ];
            }

            return response()->json([
                'success'  => true,
                'data'     => $result,
                'total_so' => $salesOrders->count(),
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function sampleIndex(Request $request)
    {
        try {
            $query = SalesOrder::with([
                    'buyer',
                    'details.item',
                    'productionOrders' => fn($q) => $q->where('type', 'sample')->with('details.item'),
                ])
                ->where('status', '!=', 'Draft')
                ->whereHas('productionOrders', fn($q) => $q->where('type', 'sample'))
                ->orderBy('created_at', 'desc');

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('so_number', 'LIKE', "%{$search}%")
                      ->orWhereHas('buyer', fn($bq) => $bq->where('name', 'LIKE', "%{$search}%"));
                });
            }

            $salesOrders = $query->get();
            $result = [];

            foreach ($salesOrders as $so) {
                foreach ($so->productionOrders as $po) {
                    $poIds = [$po->id];

                    // Sub-table IDs untuk stage yang pakai sub-table
                    $sawmillIds    = DB::table('sawmill_productions')->whereIn('ref_po_id', $poIds)->pluck('id')->toArray();
                    $kdIds         = DB::table('kd_productions')->whereIn('ref_po_id', $poIds)->pluck('id')->toArray();
                    $pembahananIds = DB::table('pembahanan_productions')->whereIn('ref_po_id', $poIds)->pluck('id')->toArray();
                    $mouldingIds   = DB::table('moulding_productions')->whereIn('ref_po_id', $poIds)->pluck('id')->toArray();

                    // Cek ada-tidaknya aktivitas per stage
                    $hasSawmill    = InventoryLog::where('transaction_type', 'SAWMILL')
                        ->whereIn('reference_id', array_merge($poIds, $sawmillIds))->exists();
                    $hasKd         = InventoryLog::where('transaction_type', 'KD')
                        ->whereIn('reference_id', array_merge($poIds, $kdIds))->exists();
                    $hasPembahanan = InventoryLog::where('transaction_type', 'PEMBAHANAN')
                        ->whereIn('reference_id', array_merge($poIds, $pembahananIds))->exists();

                    // Qty stages (langsung pakai ref_po_id atau sub-table moulding)
                    $qtyMoulding = 0;
                    if (!empty($mouldingIds)) {
                        $qtyMoulding = (float) DB::table('moulding_production_outputs')
                            ->whereIn('moulding_production_id', $mouldingIds)
                            ->sum('qty');
                    }

                    $qtyPrototype = (float) InventoryLog::where('transaction_type', 'PROTOTYPE')
                        ->whereIn('reference_id', $poIds)->where('direction', 'IN')->sum('qty');

                    $qtySanding = (float) InventoryLog::where('transaction_type', 'SANDING')
                        ->whereIn('reference_id', $poIds)->where('direction', 'IN')->sum('qty');

                    $qtyPacking = (float) InventoryLog::where('transaction_type', 'PACKING')
                        ->whereIn('reference_id', $poIds)->where('direction', 'IN')->sum('qty');

                    // Status sekuensial untuk hulu stages
                    $hasLaterThanSawmill    = $hasKd || $hasPembahanan || $qtyMoulding > 0 || $qtyPrototype > 0 || $qtySanding > 0 || $qtyPacking > 0;
                    $hasLaterThanKd         = $hasPembahanan || $qtyMoulding > 0 || $qtyPrototype > 0 || $qtySanding > 0 || $qtyPacking > 0;
                    $hasLaterThanPembahanan = $qtyMoulding > 0 || $qtyPrototype > 0 || $qtySanding > 0 || $qtyPacking > 0;

                    $statusSawmill    = $hasLaterThanSawmill && $hasSawmill    ? 'done' : ($hasLaterThanSawmill && !$hasSawmill    ? 'skip' : ($hasSawmill    ? 'in_progress' : 'waiting'));
                    $statusKd         = $hasLaterThanKd && $hasKd             ? 'done' : ($hasLaterThanKd && !$hasKd             ? 'skip' : ($hasKd         ? 'in_progress' : 'waiting'));
                    $statusPembahanan = $hasLaterThanPembahanan && $hasPembahanan ? 'done' : ($hasLaterThanPembahanan && !$hasPembahanan ? 'skip' : ($hasPembahanan ? 'in_progress' : 'waiting'));

                    $items = [];
                    foreach ($po->details as $detail) {
                        $soDetail     = $so->details->firstWhere('item_id', $detail->item_id);
                        $deliveryDate = $soDetail?->delivery_date
                            ? Carbon::parse($soDetail->delivery_date)->format('d/m/Y')
                            : '-';
                        $target = (float) $detail->qty_planned;

                        $items[] = [
                            'detail_id'         => $detail->id,
                            'item_id'           => $detail->item_id,
                            'item_name'         => $detail->item?->name ?? '-',
                            'item_code'         => $detail->item?->code ?? '-',
                            'target'            => $target,
                            'delivery_date'     => $deliveryDate,
                            'status_sanwil'     => $statusSawmill,
                            'status_kd'         => $statusKd,
                            'status_pembahanan' => $statusPembahanan,
                            'qty_moulding'      => $qtyMoulding,
                            'qty_prototype'     => $qtyPrototype,
                            'qty_sanding'       => $qtySanding,
                            'qty_packing'       => $qtyPacking,
                            'sisa'              => max(0, $target - $qtyPacking),
                            'is_done'           => ($qtyPacking >= $target && $target > 0) || $po->status === 'completed',
                        ];
                    }

                    $result[] = [
                        'so_id'      => $so->id,
                        'so_number'  => $so->so_number,
                        'so_date'    => $so->so_date ? Carbon::parse($so->so_date)->format('d/m/Y') : '-',
                        'buyer_name' => $so->buyer?->name ?? '-',
                        'po_id'      => $po->id,
                        'po_number'  => $po->po_number,
                        'po_status'  => $po->status,
                        'is_done'    => $po->status === 'completed',
                        'items'      => $items,
                    ];
                }
            }

            return response()->json([
                'success'  => true,
                'data'     => $result,
                'total_po' => count($result),
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

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

            $stages = [
                'SAWMILL'         => 'Sawmill',
                'KD'              => 'KD',
                'PEMBAHANAN'      => 'Pembahanan',
                'MOULDING'        => 'Moulding',
                'MESIN'           => 'Mesin',
                'RUSTIK_KOMPONEN' => 'Rustik Komponen',
                'SUB_ASSEMBLING'  => 'Sub Assembling',
                'RAKIT'           => 'Rakit',
                'SANDING'         => 'Sanding',
                'RUSTIK'          => 'Rustik',
                'FINISHING'       => 'Finishing',
                'QC_FINAL'        => 'QC Final',
                'PACKING'         => 'Packing',
            ];

            $result = [];
            $mapLog = fn($log) => [
                'date'             => Carbon::parse($log->date)->format('d/m/Y'),
                'time'             => $log->time,
                'item_name'        => $log->item?->name ?? '-',
                'item_code'        => $log->item?->code ?? '-',
                'warehouse_name'   => $log->warehouse?->name ?? '-',
                'qty'              => (float) $log->qty,
                'notes'            => $log->notes ?? '-',
                'user_name'        => $log->user?->name ?? '-',
                'reference_number' => $log->reference_number ?? '-',
            ];

            foreach ($stages as $type => $label) {
                $searchIds = $this->getSearchIds($type, $poIds);

                // Untuk MESIN, kumpulkan subIds agar bisa query nama mesin
                $subIds = [];
                if ($type === 'MESIN' && !empty($poIds)) {
                    $subIds = DB::table('mesin_productions')
                        ->whereIn('ref_po_id', $poIds)
                        ->pluck('id')
                        ->toArray();
                }

                $logsIn  = InventoryLog::where('transaction_type', $type)
                    ->whereIn('reference_id', $searchIds)->where('direction', 'IN')
                    ->with(['warehouse', 'item', 'user'])->orderBy('date', 'asc')->get();

                $logsOut = InventoryLog::where('transaction_type', $type)
                    ->whereIn('reference_id', $searchIds)->where('direction', 'OUT')
                    ->with(['warehouse', 'item', 'user'])->orderBy('date', 'asc')->get();

                if ($logsIn->isEmpty() && $logsOut->isEmpty()) continue;

                // Ambil nama mesin jika stage MESIN
                $machineName = null;
                if ($type === 'MESIN' && !empty($subIds)) {
                    $machineName = DB::table('mesin_production_inputs')
                        ->join('machines', 'machines.id', '=', 'mesin_production_inputs.machine_id')
                        ->whereIn('mesin_production_inputs.mesin_production_id', $subIds)
                        ->pluck('machines.name')
                        ->unique()
                        ->implode(', ');
                }

                $result[] = [
                    'stage'        => $label . ($machineName ? " — {$machineName}" : ''),
                    'type'         => $type,
                    'total_in'     => (float) $logsIn->sum('qty'),
                    'total_out'    => (float) $logsOut->sum('qty'),
                    'inputs'       => $logsIn->map($mapLog),
                    'outputs'      => $logsOut->map($mapLog),
                ];
            }

            $allProductionIds = $this->getAllProductionIds($poIds);
            $rejectLogs = InventoryLog::where('transaction_type', 'LIKE', '%REJECT%')
                ->whereIn('reference_id', $allProductionIds)->where('direction', 'IN')
                ->with(['warehouse', 'item', 'user'])->orderBy('date', 'asc')->get();

            $rejects = $rejectLogs->map(fn($log) => [
                'date'             => $log->date,
                'item_name'        => $log->item?->name ?? '-',
                'item_code'        => $log->item?->code ?? '-',
                'qty'              => (float) $log->qty,
                'transaction_type' => $log->transaction_type,
                'notes'            => $log->notes ?? '-',
                'reference_number' => $log->reference_number ?? '-',
                'user_name'        => $log->user?->name ?? '-',
            ])->toArray();

            return response()->json([
                'success'    => true,
                'so_number'  => $so->so_number,
                'buyer_name' => $so->buyer?->name ?? '-',
                'po_numbers' => $so->productionOrders->pluck('po_number'),
                'stages'     => $result,
                'rejects'    => $rejects,
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function exportExcel(Request $request)
    {
        $request->validate(['so_id' => ['required', 'integer']]);

        try {
            $so    = SalesOrder::with(['buyer', 'productionOrders', 'details.item'])
                        ->findOrFail($request->so_id);
            $poIds = $so->productionOrders->pluck('id')->toArray();

            $stages = [
                'SAWMILL'         => 'Sawmill',
                'KD'              => 'KD',
                'PEMBAHANAN'      => 'Pembahanan',
                'MOULDING'        => 'Moulding',
                'MESIN'           => 'Mesin',
                'RUSTIK_KOMPONEN' => 'Rustik Komponen',
                'SUB_ASSEMBLING'  => 'Sub Assembling',
                'RAKIT'           => 'Rakit',
                'SANDING'         => 'Sanding',
                'RUSTIK'          => 'Rustik',
                'FINISHING'       => 'Finishing',
                'QC_FINAL'        => 'QC Final',
                'PACKING'         => 'Packing',
            ];

            $stagesData = [];
            foreach ($stages as $type => $label) {
                $searchIds = $this->getSearchIds($type, $poIds);

                $logsIn  = InventoryLog::where('transaction_type', $type)
                    ->whereIn('reference_id', $searchIds)->where('direction', 'IN')
                    ->with(['warehouse', 'item', 'user'])->orderBy('date', 'asc')->get();

                $logsOut = InventoryLog::where('transaction_type', $type)
                    ->whereIn('reference_id', $searchIds)->where('direction', 'OUT')
                    ->with(['warehouse', 'item', 'user'])->orderBy('date', 'asc')->get();

                if ($logsIn->isEmpty() && $logsOut->isEmpty()) continue;

                $stagesData[] = [
                    'label'   => $label,
                    'type'    => $type,
                    'total_in'  => $logsIn->sum('qty'),
                    'total_out' => $logsOut->sum('qty'),
                    'inputs'  => $logsIn,
                    'outputs' => $logsOut,
                ];
            }

            $allProductionIds = $this->getAllProductionIds($poIds);
            $rejectLogs = InventoryLog::where('transaction_type', 'LIKE', '%REJECT%')
                ->whereIn('reference_id', $allProductionIds)->where('direction', 'IN')
                ->with(['warehouse', 'item', 'user'])->orderBy('date', 'asc')->get();

            // === BUAT EXCEL ===
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Laporan Produksi');

            $stageColors = [
                'SAWMILL'         => 'D97706',
                'KD'              => '0369A1',
                'PEMBAHANAN'      => '7C3AED',
                'MOULDING'        => '059669',
                'MESIN'           => 'DC2626',
                'RUSTIK_KOMPONEN' => '9A3412',
                'SUB_ASSEMBLING'  => '1D4ED8',
                'RAKIT'           => '0E7490',
                'SANDING'         => 'B45309',
                'RUSTIK'          => '9A3412',
                'FINISHING'       => 'BE185D',
                'QC_FINAL'        => '166534',
                'PACKING'         => '1E40AF',
            ];

            $row = 1;

            $sheet->mergeCells("A{$row}:H{$row}");
            $sheet->setCellValue("A{$row}", 'LAPORAN PRODUKSI');
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A5F']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(28);
            $row++;

            $infoRows = [
                ['No. SO',        $so->so_number],
                ['Buyer',         $so->buyer?->name ?? '-'],
                ['Item',          $so->details->first()?->item?->name ?? '-'],
                ['Kode Item',     $so->details->first()?->item?->code ?? '-'],
                ['Target Qty',    $so->details->sum('quantity') . ' pcs'],
                ['Tanggal Cetak', now()->format('d/m/Y H:i')],
            ];

            foreach ($infoRows as $info) {
                $sheet->setCellValue("A{$row}", $info[0]);
                $sheet->setCellValue("B{$row}", $info[1]);
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                $sheet->getStyle("A{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('F3F4F6');
                $sheet->mergeCells("B{$row}:H{$row}");
                $row++;
            }

            $row++;
            $colHeaders = ['TANGGAL', 'NO. DOKUMEN', 'TIPE', 'ITEM', 'GUDANG', 'QTY', 'CATATAN', 'USER'];

            foreach ($stagesData as $stage) {
                $color = $stageColors[$stage['type']] ?? '374151';

                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->setCellValue("A{$row}", strtoupper($stage['label']));
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $color]],
                    'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
                ]);
                $sheet->getRowDimension($row)->setRowHeight(22);
                $row++;

                $sheet->setCellValue("A{$row}", "Total Input: {$stage['total_out']} pcs | Total Output: {$stage['total_in']} pcs");
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(9);
                $sheet->getStyle("A{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('FFF7ED');
                $row++;

                if ($stage['outputs']->isNotEmpty()) {
                    $sheet->setCellValue("A{$row}", 'BAHAN MASUK / DIPAKAI');
                    $sheet->mergeCells("A{$row}:H{$row}");
                    $sheet->getStyle("A{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '92400E']],
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FEF3C7']],
                    ]);
                    $row++;

                    foreach ($colHeaders as $i => $h) $sheet->setCellValue(chr(65 + $i) . $row, $h);
                    $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '6B7280']],
                    ]);
                    $row++;

                    foreach ($stage['outputs'] as $log) {
                        $sheet->setCellValue("A{$row}", Carbon::parse($log->date)->format('d/m/Y'));
                        $sheet->setCellValue("B{$row}", $log->reference_number ?? '-');
                        $sheet->setCellValue("C{$row}", 'OUT');
                        $sheet->setCellValue("D{$row}", ($log->item?->code ? "[{$log->item->code}] " : '') . ($log->item?->name ?? '-'));
                        $sheet->setCellValue("E{$row}", $log->warehouse?->name ?? '-');
                        $sheet->setCellValue("F{$row}", $log->qty);
                        $sheet->setCellValue("G{$row}", $log->notes ?? '-');
                        $sheet->setCellValue("H{$row}", $log->user?->name ?? '-');
                        $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('FFFBEB');
                        $row++;
                    }
                }

                if ($stage['inputs']->isNotEmpty()) {
                    $sheet->setCellValue("A{$row}", 'HASIL / KELUAR');
                    $sheet->mergeCells("A{$row}:H{$row}");
                    $sheet->getStyle("A{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '065F46']],
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'D1FAE5']],
                    ]);
                    $row++;

                    foreach ($colHeaders as $i => $h) $sheet->setCellValue(chr(65 + $i) . $row, $h);
                    $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '6B7280']],
                    ]);
                    $row++;

                    foreach ($stage['inputs'] as $log) {
                        $sheet->setCellValue("A{$row}", Carbon::parse($log->date)->format('d/m/Y'));
                        $sheet->setCellValue("B{$row}", $log->reference_number ?? '-');
                        $sheet->setCellValue("C{$row}", 'IN');
                        $sheet->setCellValue("D{$row}", ($log->item?->code ? "[{$log->item->code}] " : '') . ($log->item?->name ?? '-'));
                        $sheet->setCellValue("E{$row}", $log->warehouse?->name ?? '-');
                        $sheet->setCellValue("F{$row}", $log->qty);
                        $sheet->setCellValue("G{$row}", $log->notes ?? '-');
                        $sheet->setCellValue("H{$row}", $log->user?->name ?? '-');
                        $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('F0FDF4');
                        $row++;
                    }
                }

                $row++;
            }

            if ($rejectLogs->isNotEmpty()) {
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->setCellValue("A{$row}", 'REJECT');
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DC2626']],
                ]);
                $sheet->getRowDimension($row)->setRowHeight(22);
                $row++;

                foreach ($colHeaders as $i => $h) $sheet->setCellValue(chr(65 + $i) . $row, $h);
                $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '6B7280']],
                ]);
                $row++;

                foreach ($rejectLogs as $log) {
                    $sheet->setCellValue("A{$row}", Carbon::parse($log->date)->format('d/m/Y'));
                    $sheet->setCellValue("B{$row}", $log->reference_number ?? '-');
                    $sheet->setCellValue("C{$row}", $log->transaction_type);
                    $sheet->setCellValue("D{$row}", ($log->item?->code ? "[{$log->item->code}] " : '') . ($log->item?->name ?? '-'));
                    $sheet->setCellValue("E{$row}", $log->warehouse?->name ?? '-');
                    $sheet->setCellValue("F{$row}", $log->qty);
                    $sheet->setCellValue("G{$row}", $log->notes ?? '-');
                    $sheet->setCellValue("H{$row}", $log->user?->name ?? '-');
                    $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('FEF2F2');
                    $row++;
                }
            }

            foreach (range('A', 'H') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
            $sheet->getStyle("A1:H" . ($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'E5E7EB']]],
            ]);

            $filename = 'Laporan_Produksi_' . str_replace('/', '-', $so->so_number) . '_' . now()->format('Ymd') . '.xlsx';
            $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $tempFile = tempnam(sys_get_temp_dir(), 'excel_');
            $writer->save($tempFile);

            return response()->download($tempFile, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}