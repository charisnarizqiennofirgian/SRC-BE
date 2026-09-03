<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\ProductionOrderDetail;
use App\Models\SalesOrder;
use App\Models\InventoryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProductionMonitoringController extends Controller
{
    private const QTY_PRODUK_JADI_FEATURE_CUTOFF = '2026-07-16 14:24:20';

    private array $subTableMap = [
        'SAWMILL'         => 'sawmill_productions',
        'KD'              => 'kd_productions',
        'PEMBAHANAN'      => 'pembahanan_productions',
        'MOULDING'        => 'moulding_productions',
        'MESIN'           => 'mesin_productions',
        'RUSTIK_KOMPONEN' => 'rustik_komponen_productions',
        'SUB_ASSEMBLING'  => 'assembling_productions',
        'RAKIT'           => 'assembling_productions',
        'QC_FINAL'        => 'qc_final_productions',
        'ANYAM'           => 'anyam_productions',
    ];

    private function getSearchIds(string $txType, array $poIds): array
    {
        if (isset($this->subTableMap[$txType])) {
            if (empty($poIds)) return [];
            return DB::table($this->subTableMap[$txType])
                ->whereIn('ref_po_id', $poIds)
                ->pluck('id')
                ->toArray();
        }
        return $poIds;
    }

    private function canRefreshInitialStock(?ProductionOrderDetail $poDetail, array $rawQty): bool
    {
        if (!$poDetail) {
            return false;
        }

        if (!empty($poDetail->current_stage)) {
            return false;
        }

        if ((float) $poDetail->qty_produced > 0) {
            return false;
        }

        return array_sum($rawQty) <= 0;
    }

    private function waterfallAllocate($detailsForItem, float $totalQty, string $targetField = 'quantity'): array
    {
        $cumulative = 0;
        $allocations = [];
        foreach ($detailsForItem as $d) {
            $targetQty = (float) $d->{$targetField};
            $remainingBudget = max(0, $totalQty - $cumulative);
            $allocated = min($targetQty, $remainingBudget);
            $allocations[$d->id] = $allocated;
            $cumulative += $allocated;
        }
        return $allocations;
    }

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

    private array $hilirStageTypes = [
        'ruskomp'    => ['RUSTIK_KOMPONEN'],
        'assembling' => ['SUB_ASSEMBLING', 'RAKIT'],
        'sanding'    => ['SANDING'],
        'rustik'     => ['RUSTIK'],
        'finishing'  => ['FINISHING'],
        'anyam'      => ['ANYAM'],
        'packing'    => ['PACKING'],
    ];

    private function applyPipelineRemaining(array $orderedQty): array
    {
        $keys      = array_keys($orderedQty);
        $result    = [];
        $suffixMax = 0.0;

        for ($i = count($keys) - 1; $i >= 0; $i--) {
            $key   = $keys[$i];
            $raw   = (float) $orderedQty[$key];
            $result[$key] = max(0, $raw - $suffixMax);
            $suffixMax    = max($suffixMax, $raw);
        }

        return $result;
    }

    private function estimateProdukJadiFromBom($componentSums, array $bomRecipeMap): float
    {
        $impliedUnits = [];
        foreach ($componentSums as $cs) {
            $qtyPerUnit = $bomRecipeMap[$cs->item_id] ?? null;
            if ($qtyPerUnit !== null && $qtyPerUnit > 0) {
                $impliedUnits[] = floor($cs->total_qty / $qtyPerUnit);
            }
        }

        if (!empty($impliedUnits)) {
            return (float) min($impliedUnits);
        }

        return (float) $componentSums->sum('total_qty');
    }

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

                $allPoDetails = $so->productionOrders->flatMap(fn($po) => $po->details);
                $poDetailQueueByItem = $allPoDetails->groupBy('item_id')->map(fn($g) => $g->sortBy('id')->values());

                $soDetailsByItem = $so->details->groupBy('item_id')->map(fn($g) => $g->sortBy('id')->values());
                $hilirAllocationCache = [];
                $qcFinalAllocationCache = [];

                $hilirSearchIds = [];
                foreach ($this->hilirStageTypes as $txTypes) {
                    foreach ($txTypes as $txType) {
                        $hilirSearchIds[$txType] = $this->getSearchIds($txType, $poIds);
                    }
                }

                $items = [];

                foreach ($so->details as $detail) {
                    $qtyOrderedCheck = (float) $detail->quantity;
                    $qtyShippedCheck = (float) ($detail->quantity_shipped ?? 0);
                    if ($qtyOrderedCheck > 0 && $qtyShippedCheck >= $qtyOrderedCheck) {
                        continue;
                    }

                    $itemId = $detail->item_id;

                    $stageTypes = [
                        'sanwil'     => 'SAWMILL',
                        'kd'         => 'KD',
                        'pembahanan' => 'PEMBAHANAN',
                        'moulding'   => 'MOULDING',
                        'mesin'      => 'MESIN',
                    ];

                    $statusHulu = [];

                    $bomRecipe = DB::table('product_boms')
                        ->where('parent_item_id', $itemId)
                        ->get(['child_item_id', 'qty']);
                    $bomRecipeMap = $bomRecipe->pluck('qty', 'child_item_id')->toArray();

                    $matchedPoDetail = null;
                    if ($poDetailQueueByItem->has($itemId) && $poDetailQueueByItem[$itemId]->isNotEmpty()) {
                        $matchedPoDetail = $poDetailQueueByItem[$itemId]->shift();
                    }
                    $detailIds = $matchedPoDetail ? [$matchedPoDetail->id] : [];

                    $qtyMoulding = 0;
                    $mouldingComponents = [];
                    if (!empty($detailIds)) {
                        $mouldingRows = DB::table('moulding_productions')
                            ->whereIn('production_order_detail_id', $detailIds)
                            ->select('id', 'qty_produk_jadi', 'created_at')
                            ->get();
                        $legacyMouldingIds = [];
                        foreach ($mouldingRows as $mr) {
                            if ($mr->qty_produk_jadi !== null) {
                                $qtyMoulding += (float) $mr->qty_produk_jadi;
                            } elseif ($mr->created_at < self::QTY_PRODUK_JADI_FEATURE_CUTOFF) {
                                $legacyMouldingIds[] = $mr->id;
                            }
                        }
                        if (!empty($legacyMouldingIds)) {
                            $legacyComponentSums = DB::table('moulding_production_outputs')
                                ->whereIn('moulding_production_id', $legacyMouldingIds)
                                ->select('item_id', DB::raw('SUM(qty) as total_qty'))
                                ->groupBy('item_id')
                                ->get();
                            $qtyMoulding += $this->estimateProdukJadiFromBom($legacyComponentSums, $bomRecipeMap);
                        }

                        $allMouldingIds = $mouldingRows->pluck('id')->toArray();
                        if (!empty($allMouldingIds)) {
                            $componentSums = DB::table('moulding_production_outputs')
                                ->whereIn('moulding_production_id', $allMouldingIds)
                                ->select('item_id', DB::raw('SUM(qty) as total_qty'))
                                ->groupBy('item_id')
                                ->get();

                            $componentItemIds = $componentSums->pluck('item_id')->toArray();
                            $componentItemsById = \App\Models\Item::whereIn('id', $componentItemIds)
                                ->get(['id', 'name', 'code'])
                                ->keyBy('id');

                            foreach ($componentSums as $cs) {
                                $compItem = $componentItemsById->get($cs->item_id);
                                $mouldingComponents[] = [
                                    'item_id'   => $cs->item_id,
                                    'item_name' => $compItem->name ?? '(item tidak ditemukan)',
                                    'item_code' => $compItem->code ?? '',
                                    'qty'       => (float) $cs->total_qty,
                                ];
                            }
                        }
                    }

                    $qtyMesin = 0;
                    $mesinComponents = [];
                    if (!empty($detailIds)) {
                        $mesinRows = DB::table('mesin_productions')
                            ->whereIn('production_order_detail_id', $detailIds)
                            ->select('id', 'qty_produk_jadi', 'created_at')
                            ->get();
                        $legacyMesinIds = [];
                        foreach ($mesinRows as $mr) {
                            if ($mr->qty_produk_jadi !== null) {
                                $qtyMesin += (float) $mr->qty_produk_jadi;
                            } elseif ($mr->created_at < self::QTY_PRODUK_JADI_FEATURE_CUTOFF) {
                                $legacyMesinIds[] = $mr->id;
                            }
                        }
                        if (!empty($legacyMesinIds)) {
                            $legacyMesinComponentSums = DB::table('mesin_production_outputs')
                                ->whereIn('mesin_production_id', $legacyMesinIds)
                                ->select('item_id', DB::raw('SUM(qty) as total_qty'))
                                ->groupBy('item_id')
                                ->get();
                            $qtyMesin += $this->estimateProdukJadiFromBom($legacyMesinComponentSums, $bomRecipeMap);
                        }

                        $allMesinIds = $mesinRows->pluck('id')->toArray();
                        if (!empty($allMesinIds)) {
                            $mesinComponentSums = DB::table('mesin_production_outputs')
                                ->whereIn('mesin_production_id', $allMesinIds)
                                ->select('item_id', DB::raw('SUM(qty) as total_qty'))
                                ->groupBy('item_id')
                                ->get();

                            $mesinComponentItemIds = $mesinComponentSums->pluck('item_id')->toArray();
                            $mesinComponentItemsById = \App\Models\Item::whereIn('id', $mesinComponentItemIds)
                                ->get(['id', 'name', 'code'])
                                ->keyBy('id');

                            foreach ($mesinComponentSums as $cs) {
                                $compItem = $mesinComponentItemsById->get($cs->item_id);
                                $mesinComponents[] = [
                                    'item_id'   => $cs->item_id,
                                    'item_name' => $compItem->name ?? '(item tidak ditemukan)',
                                    'item_code' => $compItem->code ?? '',
                                    'qty'       => (float) $cs->total_qty,
                                ];
                            }
                        }
                    }

                    $qtyRuskomp = 0;
                    if (!empty($detailIds)) {
                        $qtyRuskomp = (float) DB::table('rustik_komponen_productions')
                            ->whereIn('production_order_detail_id', $detailIds)
                            ->whereNotNull('qty_produk_jadi')
                            ->sum('qty_produk_jadi');
                    }

                    $mouldingBomChecklist = [];
                    $mesinBomChecklist    = [];

                    if ($bomRecipe->isNotEmpty()) {
                        $bomChildIds = $bomRecipe->pluck('child_item_id')->toArray();
                        $bomItemsById = \App\Models\Item::whereIn('id', $bomChildIds)
                            ->get(['id', 'name', 'code'])
                            ->keyBy('id');

                        $mouldingActualByItem = collect($mouldingComponents)->keyBy('item_id');
                        $mesinActualByItem    = collect($mesinComponents)->keyBy('item_id');

                        foreach ($bomRecipe as $bc) {
                            $bomItem        = $bomItemsById->get($bc->child_item_id);
                            $mouldingActual = $mouldingActualByItem->get($bc->child_item_id);
                            $mesinActual    = $mesinActualByItem->get($bc->child_item_id);

                            $mouldingBomChecklist[] = [
                                'item_id'    => $bc->child_item_id,
                                'item_name'  => $bomItem->name ?? '(item tidak ditemukan)',
                                'item_code'  => $bomItem->code ?? '',
                                'qty_actual' => $mouldingActual ? (float) $mouldingActual['qty'] : 0,
                                'done'       => (bool) $mouldingActual,
                            ];

                            $mesinBomChecklist[] = [
                                'item_id'    => $bc->child_item_id,
                                'item_name'  => $bomItem->name ?? '(item tidak ditemukan)',
                                'item_code'  => $bomItem->code ?? '',
                                'qty_actual' => $mesinActual ? (float) $mesinActual['qty'] : 0,
                                'done'       => (bool) $mesinActual,
                            ];
                        }
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

                            if ($key === 'sanwil') {
                                $anyLaterActiveSanwil = $anyLaterActive || $qtyMoulding > 0 || $qtyMesin > 0;

                                if ($hasActivity) {
                                    $statusHulu[$key] = $anyLaterActiveSanwil ? 'done' : 'in_progress';
                                } elseif ($anyLaterActiveSanwil) {
                                    $statusHulu[$key] = 'done';
                                } else {
                                    $statusHulu[$key] = 'waiting';
                                }
                                continue;
                            }

                            if ($anyLaterActive && !$hasActivity)     $statusHulu[$key] = 'skip';
                            elseif ($anyLaterActive && $hasActivity)  $statusHulu[$key] = 'done';
                            elseif ($hasActivity)                     $statusHulu[$key] = 'in_progress';
                            else                                      $statusHulu[$key] = 'waiting';
                        }
                    }

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

                    if ($soDetailsByItem->has($itemId) && $soDetailsByItem[$itemId]->count() > 1) {
                        $detailsForItem = $soDetailsByItem[$itemId];
                        foreach ($qtyHilir as $key => $rawQty) {
                            if (!isset($hilirAllocationCache[$itemId][$key])) {
                                $hilirAllocationCache[$itemId][$key] = $this->waterfallAllocate($detailsForItem, $rawQty);
                            }
                            $qtyHilir[$key] = $hilirAllocationCache[$itemId][$key][$detail->id] ?? 0;
                        }
                    }

                    $qtyHilir['ruskomp'] = $qtyRuskomp;

                    $target = (float) $detail->quantity;

                    $qcFinalSearchIds = $this->getSearchIds('QC_FINAL', $poIds);
                    $qtyQcFinal = 0;
                    if (!empty($qcFinalSearchIds)) {
                        $qtyQcFinal = InventoryLog::where('transaction_type', 'QC_FINAL')
                            ->where('item_id', $itemId)
                            ->where('direction', 'IN')
                            ->whereIn('reference_id', $qcFinalSearchIds)
                            ->sum('qty');
                    }

                    if ($soDetailsByItem->has($itemId) && $soDetailsByItem[$itemId]->count() > 1) {
                        $detailsForItem = $soDetailsByItem[$itemId];
                        if (!isset($qcFinalAllocationCache[$itemId])) {
                            $qcFinalAllocationCache[$itemId] = $this->waterfallAllocate($detailsForItem, (float) $qtyQcFinal);
                        }
                        $qtyQcFinal = $qcFinalAllocationCache[$itemId][$detail->id] ?? 0;
                    }

                    $allProductionIds = $this->getAllProductionIds($poIds);
                    $qtyReject = InventoryLog::where('transaction_type', 'LIKE', '%REJECT%')
                        ->when(!empty($allProductionIds), fn($q) => $q->whereIn('reference_id', $allProductionIds))
                        ->where('direction', 'IN')
                        ->sum('qty');

                    $qtyPacking  = $qtyHilir['packing'];
                    $poCompleted = $so->productionOrders->where('status', 'completed')->count() > 0;

                    $rawQtyForPipeline = [
                        'moulding'   => $qtyMoulding,
                        'mesin'      => $qtyMesin,
                        'ruskomp'    => $qtyHilir['ruskomp'],
                        'assembling' => $qtyHilir['assembling'],
                        'sanding'    => $qtyHilir['sanding'],
                        'rustik'     => $qtyHilir['rustik'],
                        'finishing'  => $qtyHilir['finishing'],
                        'anyam'      => $qtyHilir['anyam'],
                        'qc_final'   => (float) $qtyQcFinal,
                        'packing'    => $qtyHilir['packing'],
                    ];
                    $pipelineRemaining = $this->applyPipelineRemaining($rawQtyForPipeline);

                    $stok = (float) ($matchedPoDetail->initial_stock_snapshot ?? 0);
                    $stokUpdatable = $this->canRefreshInitialStock($matchedPoDetail, $rawQtyForPipeline);

                    $items[] = [
                        'detail_id'         => $detail->id,
                        'production_order_detail_id' => $matchedPoDetail?->id,
                        'item_id'           => $itemId,
                        'item_name'         => $detail->item?->name ?? '-',
                        'item_code'         => $detail->item?->code ?? '-',
                        'target'            => $target,
                        'stok'              => $stok,
                        'stok_updatable'    => $stokUpdatable,
                        'delivery_date'     => $detail->delivery_date
                                                ? Carbon::parse($detail->delivery_date)->format('d/m/Y')
                                                : '-',

                        'status_sanwil'     => $statusHulu['sanwil'],
                        'status_kd'         => $statusHulu['kd'],
                        'status_pembahanan' => $statusHulu['pembahanan'],
                        'qty_moulding'      => $pipelineRemaining['moulding'],
                        'moulding_components' => $mouldingComponents,
                        'moulding_bom_checklist' => $mouldingBomChecklist,
                        'qty_mesin'         => $pipelineRemaining['mesin'],
                        'mesin_components'  => $mesinComponents,
                        'mesin_bom_checklist' => $mesinBomChecklist,

                        'qty_ruskomp'       => $pipelineRemaining['ruskomp'],
                        'qty_assembling'    => $pipelineRemaining['assembling'],
                        'qty_sanding'       => $pipelineRemaining['sanding'],
                        'qty_rustik'        => $pipelineRemaining['rustik'],
                        'qty_finishing'     => $pipelineRemaining['finishing'],
                        'qty_anyam'         => $pipelineRemaining['anyam'],
                        'qty_qc_final'      => $pipelineRemaining['qc_final'],
                        'qty_packing'       => $pipelineRemaining['packing'],
                        'qty_reject'        => (float) $qtyReject,
                        'has_reject'        => $qtyReject > 0,

                        'sisa'              => max(0, $target - $stok - $qtyPacking),
                        'is_done'           => (($qtyPacking + $stok) >= $target && $target > 0) || $poCompleted,
                    ];
                }

                if (empty($items)) {
                    continue;
                }

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

                    $sawmillIds    = DB::table('sawmill_productions')->whereIn('ref_po_id', $poIds)->pluck('id')->toArray();
                    $kdIds         = DB::table('kd_productions')->whereIn('ref_po_id', $poIds)->pluck('id')->toArray();
                    $pembahananIds = DB::table('pembahanan_productions')->whereIn('ref_po_id', $poIds)->pluck('id')->toArray();
                    $mouldingIds   = DB::table('moulding_productions')->whereIn('ref_po_id', $poIds)->pluck('id')->toArray();

                    $hasSawmill    = InventoryLog::where('transaction_type', 'SAWMILL')
                        ->whereIn('reference_id', $sawmillIds)->exists();
                    $hasKd         = InventoryLog::where('transaction_type', 'KD')
                        ->whereIn('reference_id', $kdIds)->exists();
                    $hasPembahanan = InventoryLog::where('transaction_type', 'PEMBAHANAN')
                        ->whereIn('reference_id', $pembahananIds)->exists();

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

                    $hasLaterThanKd         = $hasPembahanan || $qtyMoulding > 0 || $qtyPrototype > 0 || $qtySanding > 0 || $qtyPacking > 0;
                    $hasLaterThanPembahanan = $qtyMoulding > 0 || $qtyPrototype > 0 || $qtySanding > 0 || $qtyPacking > 0;

                    $statusKd         = $hasLaterThanKd && $hasKd             ? 'done' : ($hasLaterThanKd && !$hasKd             ? 'skip' : ($hasKd         ? 'in_progress' : 'waiting'));
                    $statusPembahanan = $hasLaterThanPembahanan && $hasPembahanan ? 'done' : ($hasLaterThanPembahanan && !$hasPembahanan ? 'skip' : ($hasPembahanan ? 'in_progress' : 'waiting'));

                    $soDetailQueueByItem = $so->details->groupBy('item_id')->map(fn($g) => $g->sortBy('id')->values());
                    $poDetailsByItem = $po->details->groupBy('item_id')->map(fn($g) => $g->sortBy('id')->values());
                    $sampleHilirAllocationCache = [];

                    $items = [];
                    foreach ($po->details as $detail) {
                        $soDetail = null;
                        if ($soDetailQueueByItem->has($detail->item_id) && $soDetailQueueByItem[$detail->item_id]->isNotEmpty()) {
                            $soDetail = $soDetailQueueByItem[$detail->item_id]->shift();
                        }

                        $qtyOrderedCheck = (float) ($soDetail?->quantity ?? 0);
                        $qtyShippedCheck = (float) ($soDetail?->quantity_shipped ?? 0);
                        if ($qtyOrderedCheck > 0 && $qtyShippedCheck >= $qtyOrderedCheck) {
                            continue;
                        }

                        $deliveryDate = $soDetail?->delivery_date
                            ? Carbon::parse($soDetail->delivery_date)->format('d/m/Y')
                            : '-';
                        $target = (float) $detail->qty_planned;
                        $itemId = $detail->item_id;

                        $itemMouldingIds = DB::table('moulding_productions')
                            ->where('production_order_detail_id', $detail->id)
                            ->pluck('id')
                            ->toArray();

                        $componentSums = !empty($itemMouldingIds)
                            ? DB::table('moulding_production_outputs')
                                ->whereIn('moulding_production_id', $itemMouldingIds)
                                ->select('item_id', DB::raw('SUM(qty) as total_qty'))
                                ->groupBy('item_id')
                                ->get()
                            : collect();

                        $bomRecipeSample = DB::table('product_boms')
                            ->where('parent_item_id', $itemId)
                            ->get(['child_item_id', 'qty']);
                        $bomRecipeMapSample = $bomRecipeSample->pluck('qty', 'child_item_id')->toArray();

                        $itemQtyMoulding = $this->estimateProdukJadiFromBom($componentSums, $bomRecipeMapSample);

                        $itemQtyPrototype = (float) InventoryLog::where('transaction_type', 'PROTOTYPE')
                            ->whereIn('reference_id', $poIds)->where('direction', 'IN')
                            ->where('item_id', $itemId)->sum('qty');

                        $itemQtySanding = (float) InventoryLog::where('transaction_type', 'SANDING')
                            ->whereIn('reference_id', $poIds)->where('direction', 'IN')
                            ->where('item_id', $itemId)->sum('qty');

                        $itemQtyPacking = (float) InventoryLog::where('transaction_type', 'PACKING')
                            ->whereIn('reference_id', $poIds)->where('direction', 'IN')
                            ->where('item_id', $itemId)->sum('qty');

                        if ($poDetailsByItem->has($itemId) && $poDetailsByItem[$itemId]->count() > 1) {
                            $poDetailsForItem = $poDetailsByItem[$itemId];
                            if (!isset($sampleHilirAllocationCache[$itemId]['prototype'])) {
                                $sampleHilirAllocationCache[$itemId]['prototype'] = $this->waterfallAllocate($poDetailsForItem, $itemQtyPrototype, 'qty_planned');
                            }
                            if (!isset($sampleHilirAllocationCache[$itemId]['sanding'])) {
                                $sampleHilirAllocationCache[$itemId]['sanding'] = $this->waterfallAllocate($poDetailsForItem, $itemQtySanding, 'qty_planned');
                            }
                            if (!isset($sampleHilirAllocationCache[$itemId]['packing'])) {
                                $sampleHilirAllocationCache[$itemId]['packing'] = $this->waterfallAllocate($poDetailsForItem, $itemQtyPacking, 'qty_planned');
                            }
                            $itemQtyPrototype = $sampleHilirAllocationCache[$itemId]['prototype'][$detail->id] ?? 0;
                            $itemQtySanding   = $sampleHilirAllocationCache[$itemId]['sanding'][$detail->id] ?? 0;
                            $itemQtyPacking   = $sampleHilirAllocationCache[$itemId]['packing'][$detail->id] ?? 0;
                        }

                        $itemHasLaterThanSawmill = $hasKd || $hasPembahanan
                            || $itemQtyMoulding > 0 || $itemQtyPrototype > 0 || $itemQtySanding > 0 || $itemQtyPacking > 0;

                        if ($hasSawmill) {
                            $itemStatusSawmill = $itemHasLaterThanSawmill ? 'done' : 'in_progress';
                        } elseif ($itemHasLaterThanSawmill) {
                            $itemStatusSawmill = 'done';
                        } else {
                            $itemStatusSawmill = 'waiting';
                        }

                        $itemMouldingComponents = [];
                        if ($componentSums->isNotEmpty()) {
                            $componentItemIds = $componentSums->pluck('item_id')->toArray();
                            $componentItemsById = \App\Models\Item::whereIn('id', $componentItemIds)
                                ->get(['id', 'name', 'code'])
                                ->keyBy('id');

                            foreach ($componentSums as $cs) {
                                $compItem = $componentItemsById->get($cs->item_id);
                                $itemMouldingComponents[] = [
                                    'item_id'   => $cs->item_id,
                                    'item_name' => $compItem->name ?? '(item tidak ditemukan)',
                                    'item_code' => $compItem->code ?? '',
                                    'qty'       => (float) $cs->total_qty,
                                ];
                            }
                        }

                        $itemMouldingBomChecklist = [];
                        if ($bomRecipeSample->isNotEmpty()) {
                            $bomChildIdsSample  = $bomRecipeSample->pluck('child_item_id')->toArray();
                            $bomItemsByIdSample = \App\Models\Item::whereIn('id', $bomChildIdsSample)
                                ->get(['id', 'name', 'code'])
                                ->keyBy('id');
                            $mouldingActualByItemSample = collect($itemMouldingComponents)->keyBy('item_id');

                            foreach ($bomRecipeSample as $bc) {
                                $bomItem = $bomItemsByIdSample->get($bc->child_item_id);
                                $actual  = $mouldingActualByItemSample->get($bc->child_item_id);
                                $itemMouldingBomChecklist[] = [
                                    'item_id'    => $bc->child_item_id,
                                    'item_name'  => $bomItem->name ?? '(item tidak ditemukan)',
                                    'item_code'  => $bomItem->code ?? '',
                                    'qty_actual' => $actual ? (float) $actual['qty'] : 0,
                                    'done'       => (bool) $actual,
                                ];
                            }
                        }


                        $rawQtyForPipelineSample = [
                            'moulding'  => $itemQtyMoulding,
                            'prototype' => $itemQtyPrototype,
                            'sanding'   => $itemQtySanding,
                            'packing'   => $itemQtyPacking,
                        ];
                        $pipelineRemainingSample = $this->applyPipelineRemaining($rawQtyForPipelineSample);

                        $stokSample = (float) ($detail->initial_stock_snapshot ?? 0);
                        $stokUpdatableSample = $this->canRefreshInitialStock($detail, $rawQtyForPipelineSample);

                        $items[] = [
                            'detail_id'         => $detail->id,
                            'production_order_detail_id' => $detail->id,
                            'item_id'           => $itemId,
                            'item_name'         => $detail->item?->name ?? '-',
                            'item_code'         => $detail->item?->code ?? '-',
                            'target'            => $target,
                            'stok'              => $stokSample,
                            'stok_updatable'    => $stokUpdatableSample,
                            'delivery_date'     => $deliveryDate,
                            'status_sanwil'     => $itemStatusSawmill,
                            'status_kd'         => $statusKd,
                            'status_pembahanan' => $statusPembahanan,
                            'qty_moulding'      => $pipelineRemainingSample['moulding'],
                            'moulding_components' => $itemMouldingComponents,
                            'moulding_bom_checklist' => $itemMouldingBomChecklist,
                            'qty_prototype'     => $pipelineRemainingSample['prototype'],
                            'qty_sanding'       => $pipelineRemainingSample['sanding'],
                            'qty_packing'       => $pipelineRemainingSample['packing'],
                            'sisa'              => max(0, $target - $stokSample - $itemQtyPacking),
                            'is_done'           => (($itemQtyPacking + $stokSample) >= $target && $target > 0) || $po->status === 'completed',
                        ];
                    }


                    if (empty($items)) {
                        continue;
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
                'ANYAM'           => 'Anyam',
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
                'ANYAM'           => 'Anyam',
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

    private function hasAnyProductionActivityForItem(int $productionOrderId, int $itemId, ProductionOrderDetail $poDetail): bool
    {
        if (!empty($poDetail->current_stage) || (float) $poDetail->qty_produced > 0) {
            return true;
        }

        if (DB::table('moulding_productions')->where('production_order_detail_id', $poDetail->id)->exists()
            || DB::table('mesin_productions')->where('production_order_detail_id', $poDetail->id)->exists()
            || DB::table('rustik_komponen_productions')->where('production_order_detail_id', $poDetail->id)->exists()) {
            return true;
        }

        $poIds = [$productionOrderId];

        foreach ($this->hilirStageTypes as $txTypes) {
            foreach ($txTypes as $txType) {
                $searchIds = $this->getSearchIds($txType, $poIds);
                if (empty($searchIds)) {
                    continue;
                }
                if (InventoryLog::where('transaction_type', $txType)
                    ->whereIn('reference_id', $searchIds)
                    ->where('direction', 'IN')
                    ->where('item_id', $itemId)
                    ->exists()) {
                    return true;
                }
            }
        }

        $qcFinalSearchIds = $this->getSearchIds('QC_FINAL', $poIds);
        if (!empty($qcFinalSearchIds)) {
            if (InventoryLog::where('transaction_type', 'QC_FINAL')
                ->whereIn('reference_id', $qcFinalSearchIds)
                ->where('direction', 'IN')
                ->where('item_id', $itemId)
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    public function refreshInitialStock($productionOrderDetailId)
    {
        try {
            $detail = ProductionOrderDetail::findOrFail($productionOrderDetailId);

            if ($this->hasAnyProductionActivityForItem($detail->production_order_id, $detail->item_id, $detail)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak bisa diperbarui otomatis — item ini sudah ada progres produksi.',
                ], 422);
            }

            $detail->update([
                'initial_stock_snapshot' => Inventory::getAvailableFinishedStock($detail->item_id),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Stok awal berhasil diperbarui.',
                'data'    => ['initial_stock_snapshot' => (float) $detail->initial_stock_snapshot],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function setInitialStockManual(Request $request, $productionOrderDetailId)
    {
        $validated = $request->validate([
            'value' => 'required|numeric|min:0',
        ]);

        try {
            $detail = ProductionOrderDetail::findOrFail($productionOrderDetailId);

            $detail->update([
                'initial_stock_snapshot' => $validated['value'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Stok awal berhasil disimpan.',
                'data'    => ['initial_stock_snapshot' => (float) $detail->initial_stock_snapshot],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
