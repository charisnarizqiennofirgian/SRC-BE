<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\ProductionCancellation;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionCancellationService
{
    public const STAGES = [
        'moulding' => [
            'label'          => 'Moulding',
            'types'          => ['MOULDING'],
            'reference_type' => 'MouldingProduction',
            'table'          => 'moulding_productions',
            'children'       => [
                'moulding_production_rejects' => 'moulding_production_id',
                'moulding_production_inputs'  => 'moulding_production_id',
                'moulding_production_outputs' => 'moulding_production_id',
            ],
        ],
        'mesin' => [
            'label'          => 'Mesin',
            'types'          => ['MESIN'],
            'reference_type' => 'MesinProduction',
            'table'          => 'mesin_productions',
            'children'       => [
                'mesin_production_rejects' => 'mesin_production_id',
                'mesin_production_outputs' => 'mesin_production_id',
                'mesin_production_inputs'  => 'mesin_production_id',
            ],
        ],
        'rustik_komponen' => [
            'label'          => 'Rustik Komponen',
            'types'          => ['RUSTIK_KOMPONEN'],
            'reference_type' => 'RustikKomponenProduction',
            'table'          => 'rustik_komponen_productions',
            'children'       => [
                'rustik_komponen_rejects' => 'rustik_komponen_production_id',
                'rustik_komponen_outputs' => 'rustik_komponen_production_id',
                'rustik_komponen_inputs'  => 'rustik_komponen_production_id',
            ],
        ],
        'assembling' => [
            'label'          => 'Assembling',
            'types'          => ['RAKIT', 'SUB_ASSEMBLING'],
            'reference_type' => 'AssemblingProduction',
            'table'          => 'assembling_productions',
            'children'       => [
                'assembling_production_rejects' => 'assembling_production_id',
                'assembling_production_outputs' => 'assembling_production_id',
                'assembling_production_inputs'  => 'assembling_production_id',
            ],
        ],
        'sanding' => [
            'label'          => 'Sanding',
            'types'          => ['SANDING'],
            'reference_type' => 'ProductionOrder',
            'table'          => null,
            'children'       => [],
        ],
        'rustik' => [
            'label'          => 'Rustik',
            'types'          => ['RUSTIK'],
            'reference_type' => 'ProductionOrder',
            'table'          => null,
            'children'       => [],
        ],
        'finishing' => [
            'label'          => 'Finishing',
            'types'          => ['FINISHING'],
            'reference_type' => 'ProductionOrder',
            'table'          => null,
            'children'       => [],
        ],
        'anyam' => [
            'label'          => 'Anyam',
            'types'          => ['ANYAM'],
            'reference_type' => 'AnyamProduction',
            'table'          => 'anyam_productions',
            'children'       => [],
        ],
        'qc_final' => [
            'label'          => 'QC Final',
            'types'          => ['QC_FINAL'],
            'reference_type' => 'QcFinalProduction',
            'table'          => 'qc_final_productions',
            'children'       => [
                'qc_final_reject_items' => 'qc_final_production_id',
                'qc_final_passed_items' => 'qc_final_production_id',
            ],
        ],
        'packing' => [
            'label'          => 'Packing',
            'types'          => ['PACKING'],
            'reference_type' => 'ProductionOrder',
            'table'          => null,
            'children'       => [],
        ],
    ];

    private const REJECT_TYPES = ['REJECT', 'QC_REJECT'];

    public static function allTypes(): array
    {
        return collect(self::STAGES)->pluck('types')->flatten()->values()->all();
    }

    public static function stageByType(string $transactionType): ?string
    {
        foreach (self::STAGES as $key => $config) {
            if (in_array($transactionType, $config['types'], true)) {
                return $key;
            }
        }
        return null;
    }

    public function listDocuments(array $filters, int $perPage = 20)
    {
        $sub = DB::table('inventory_logs as l')
            ->whereIn('l.transaction_type', self::allTypes())
            ->whereNotNull('l.reference_number');

        $poParts = ["CASE WHEN l.reference_type = 'ProductionOrder' THEN l.reference_id END"];
        $i = 0;
        foreach (self::STAGES as $config) {
            if (!$config['table']) {
                continue;
            }
            $alias = 'h' . $i++;
            $refType = $config['reference_type'];
            $sub->leftJoin("{$config['table']} as {$alias}", function ($join) use ($alias, $refType) {
                $join->on("{$alias}.id", '=', 'l.reference_id')
                    ->where('l.reference_type', '=', $refType);
            });
            $poParts[] = "{$alias}.ref_po_id";
        }

        $sub->groupBy('l.reference_number', 'l.transaction_type', 'l.reference_type')
            ->select(
                'l.reference_number as document_number',
                'l.transaction_type',
                'l.reference_type',
                DB::raw('MIN(l.date) as document_date'),
                DB::raw('MAX(l.created_at) as created_at'),
                DB::raw('MAX(l.user_id) as user_id'),
                DB::raw('MAX(COALESCE(' . implode(', ', $poParts) . ')) as po_id')
            );

        $query = DB::query()->fromSub($sub, 'd')
            ->leftJoin('production_orders as po', 'po.id', '=', 'd.po_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->select('d.*', 'po.po_number', 'po.status as po_status', 'u.name as user_name');

        if (!empty($filters['stage']) && isset(self::STAGES[$filters['stage']])) {
            $query->whereIn('d.transaction_type', self::STAGES[$filters['stage']]['types']);
        }
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('d.document_number', 'like', $search)
                    ->orWhere('po.po_number', 'like', $search);
            });
        }
        if (!empty($filters['date_from'])) {
            $query->where('d.document_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('d.document_date', '<=', $filters['date_to']);
        }

        $paginator = $query->orderByDesc('d.created_at')->paginate($perPage);

        $docNumbers = collect($paginator->items())->pluck('document_number')->all();
        $summaries = [];
        if (!empty($docNumbers)) {
            $rows = DB::table('inventory_logs as l')
                ->join('items as i', 'i.id', '=', 'l.item_id')
                ->whereIn('l.reference_number', $docNumbers)
                ->whereIn('l.transaction_type', self::allTypes())
                ->where('l.direction', 'IN')
                ->groupBy('l.reference_number', 'i.name')
                ->select('l.reference_number', 'i.name', DB::raw('SUM(l.qty) as qty'))
                ->get();
            foreach ($rows as $r) {
                $summaries[$r->reference_number][] = ['item_name' => $r->name, 'qty' => (float) $r->qty];
            }
        }

        $paginator->getCollection()->transform(function ($row) use ($summaries) {
            $stage = self::stageByType($row->transaction_type);
            return [
                'document_number' => $row->document_number,
                'stage'           => $stage,
                'stage_label'     => self::STAGES[$stage]['label'] ?? $row->transaction_type,
                'document_date'   => $row->document_date,
                'created_at'      => $row->created_at,
                'po_id'           => $row->po_id,
                'po_number'       => $row->po_number,
                'po_status'       => $row->po_status,
                'user_name'       => $row->user_name,
                'outputs'         => $summaries[$row->document_number] ?? [],
            ];
        });

        return $paginator;
    }

    public function preview(string $documentNumber): array
    {
        $plan = $this->buildPlan($documentNumber, false);

        $itemIds = $plan['logs']->pluck('item_id')->unique()->all();
        $whIds   = $plan['logs']->pluck('warehouse_id')->unique()->all();
        $items   = Item::whereIn('id', $itemIds)->get(['id', 'code', 'name', 'type'])->keyBy('id');
        $whs     = Warehouse::whereIn('id', $whIds)->get(['id', 'code', 'name'])->keyBy('id');

        return [
            'document_number' => $documentNumber,
            'stage'           => $plan['stage'],
            'stage_label'     => self::STAGES[$plan['stage']]['label'],
            'document_date'   => optional($plan['logs']->first())->date,
            'po_id'           => $plan['po']?->id,
            'po_number'       => $plan['po']?->po_number,
            'po_status'       => $plan['po']?->status,
            'notes'           => $plan['header']->notes ?? null,
            'lines'           => $plan['logs']->map(fn($l) => [
                'id'             => $l->id,
                'direction'      => $l->direction,
                'is_reject'      => in_array($l->transaction_type, self::REJECT_TYPES, true),
                'item_code'      => $items->get($l->item_id)?->code,
                'item_name'      => $items->get($l->item_id)?->name ?? "Item #{$l->item_id}",
                'warehouse_name' => $whs->get($l->warehouse_id)?->name ?? "Gudang #{$l->warehouse_id}",
                'qty'            => (float) $l->qty,
                'finishing'      => $l->finishing,
            ])->values(),
            'blockers'        => $plan['blockers'],
            'can_cancel'      => empty($plan['blockers']),
        ];
    }

    public function cancel(string $documentNumber, string $reason): ProductionCancellation
    {
        return DB::transaction(function () use ($documentNumber, $reason) {
            $plan = $this->buildPlan($documentNumber, true);

            if (!empty($plan['blockers'])) {
                throw ValidationException::withMessages(['document_number' => $plan['blockers']]);
            }

            $this->applyItemChanges($plan['itemBucketDeltas'], $plan['itemStockDeltas']);
            $this->applyInventoryChanges($plan['inventoryDeltas'], $plan['po']?->id);

            DB::table('inventory_logs')->whereIn('id', $plan['logs']->pluck('id')->all())->delete();

            $config = self::STAGES[$plan['stage']];
            if ($config['table'] && $plan['header']) {
                foreach ($config['children'] as $childTable => $fk) {
                    DB::table($childTable)->where($fk, $plan['header']->id)->delete();
                }
                DB::table($config['table'])->where('id', $plan['header']->id)->delete();
            }

            return ProductionCancellation::create([
                'document_number' => $documentNumber,
                'stage'           => $plan['stage'],
                'reference_type'  => $config['reference_type'],
                'reference_id'    => $plan['header']->id ?? $plan['po']?->id,
                'ref_po_id'       => $plan['po']?->id,
                'po_number'       => $plan['po']?->po_number,
                'document_date'   => optional($plan['logs']->first())->date,
                'reason'          => $reason,
                'snapshot'        => [
                    'header'   => $plan['header'],
                    'children' => $plan['children'],
                    'logs'     => $plan['logs']->values()->all(),
                ],
                'cancelled_by'    => Auth::id(),
            ]);
        });
    }

    private function buildPlan(string $documentNumber, bool $lock): array
    {
        $first = DB::table('inventory_logs')
            ->where('reference_number', $documentNumber)
            ->whereIn('transaction_type', self::allTypes())
            ->first();

        if (!$first) {
            throw ValidationException::withMessages([
                'document_number' => ["Dokumen {$documentNumber} tidak ditemukan atau bukan transaksi produksi yang bisa dibatalkan."],
            ]);
        }

        $stage  = self::stageByType($first->transaction_type);
        $config = self::STAGES[$stage];

        $logsQuery = DB::table('inventory_logs')
            ->where('reference_number', $documentNumber)
            ->where('reference_type', $config['reference_type'])
            ->whereIn('transaction_type', array_merge($config['types'], self::REJECT_TYPES))
            ->orderBy('id');
        if ($lock) {
            $logsQuery->lockForUpdate();
        }
        $logs = $logsQuery->get();

        $header   = null;
        $children = [];
        if ($config['table']) {
            $header = DB::table($config['table'])->where('document_number', $documentNumber)->first();
            if ($header) {
                $logs = $logs->filter(fn($l) => (int) $l->reference_id === (int) $header->id)->values();
                foreach ($config['children'] as $childTable => $fk) {
                    $children[$childTable] = DB::table($childTable)->where($fk, $header->id)->get()->all();
                }
            }
        }

        $poId = $header->ref_po_id ?? ($config['reference_type'] === 'ProductionOrder' ? $first->reference_id : null);
        $po   = $poId ? ProductionOrder::find($poId) : null;

        $blockers = [];
        if ($po && $po->status === 'completed') {
            $blockers[] = "PO {$po->po_number} sudah berstatus selesai (completed). Transaksinya tidak bisa dibatalkan.";
        }

        if (isset(StageCompletionService::STAGES[$stage]) && !empty($header->production_order_detail_id)) {
            $completedAt = DB::table('production_order_details')
                ->where('id', $header->production_order_detail_id)
                ->value("{$stage}_completed_at");
            if ($completedAt) {
                $blockers[] = "Produk di dokumen ini sudah ditandai Selesai {$config['label']}. Batalkan status selesai dulu di menu {$config['label']} sebelum membatalkan transaksinya.";
            }
        }

        $inventoryDeltas  = [];
        $itemBucketDeltas = [];
        $itemStockDeltas  = [];

        $addInv = function (int $whId, int $itemId, float $pcs, float $m3 = 0, ?string $bucket = null, float $bucketQty = 0) use (&$inventoryDeltas) {
            $key = "{$whId}:{$itemId}";
            if (!isset($inventoryDeltas[$key])) {
                $inventoryDeltas[$key] = [
                    'warehouse_id' => $whId,
                    'item_id'      => $itemId,
                    'qty_pcs'      => 0.0,
                    'qty_m3'       => 0.0,
                    'qty_natural'  => 0.0,
                    'qty_warna'    => 0.0,
                ];
            }
            $inventoryDeltas[$key]['qty_pcs'] += $pcs;
            $inventoryDeltas[$key]['qty_m3']  += $m3;
            if ($bucket) {
                $inventoryDeltas[$key][$bucket] += $bucketQty;
            }
        };
        $addItemBucket = function (int $itemId, string $bucket, float $qty) use (&$itemBucketDeltas) {
            $itemBucketDeltas[$itemId][$bucket] = ($itemBucketDeltas[$itemId][$bucket] ?? 0) + $qty;
        };

        foreach ($logs as $l) {
            $sign = $l->direction === 'IN' ? -1 : 1;
            $addInv((int) $l->warehouse_id, (int) $l->item_id, $sign * (float) $l->qty, $sign * (float) $l->qty_m3);
        }

        $componentIds = Item::whereIn('id', $this->collectItemIds($logs, $children))
            ->where('type', Item::TYPE_COMPONENT)
            ->pluck('id')
            ->flip();
        $isComponent = fn($itemId) => $componentIds->has((int) $itemId);
        $bucketOf    = fn($finishing) => $finishing === 'warna' ? 'qty_warna' : 'qty_natural';
        $whId        = fn(string $code) => Warehouse::where('code', $code)->value('id');

        switch ($stage) {
            case 'moulding':
                $s4s = $whId('S4S');
                foreach ($children['moulding_production_outputs'] ?? [] as $o) {
                    if ($isComponent($o->item_id) && $o->finishing) {
                        $b = $bucketOf($o->finishing);
                        $addItemBucket($o->item_id, $b, -(float) $o->qty);
                        $addInv($s4s, $o->item_id, 0, 0, $b, -(float) $o->qty);
                    }
                }
                break;

            case 'mesin':
                $s4s   = $whId('S4S');
                $mesin = $whId('MESIN');
                $inputsById = collect($children['mesin_production_inputs'] ?? [])->keyBy('id');
                foreach ($inputsById as $in) {
                    if ($isComponent($in->item_id) && $in->finishing) {
                        $b = $bucketOf($in->finishing);
                        $addItemBucket($in->item_id, $b, (float) $in->qty);
                        $addInv($s4s, $in->item_id, 0, 0, $b, (float) $in->qty);
                    }
                }
                foreach ($children['mesin_production_outputs'] ?? [] as $o) {
                    $in = $inputsById->get($o->mesin_production_input_id);
                    if ($in && $in->finishing && $isComponent($o->item_id)) {
                        $b = $bucketOf($in->finishing);
                        $addItemBucket($o->item_id, $b, -(float) $o->qty);
                        $addInv($mesin, $o->item_id, 0, 0, $b, -(float) $o->qty);
                    }
                }
                break;

            case 'rustik_komponen':
                $mesin   = $whId('MESIN');
                $ruskomp = $whId('RUSKOMP');
                foreach ($children['rustik_komponen_inputs'] ?? [] as $in) {
                    if ($isComponent($in->item_id) && $in->finishing) {
                        $b = $bucketOf($in->finishing);
                        $addItemBucket($in->item_id, $b, (float) $in->qty);
                        $addInv($mesin, $in->item_id, 0, 0, $b, (float) $in->qty);
                    }
                }
                foreach ($children['rustik_komponen_outputs'] ?? [] as $o) {
                    if ($isComponent($o->item_id) && $o->finishing) {
                        $b = $bucketOf($o->finishing);
                        $addItemBucket($o->item_id, $b, -(float) $o->qty);
                        $addInv($ruskomp, $o->item_id, 0, 0, $b, -(float) $o->qty);
                    }
                }
                break;

            case 'assembling':
                foreach ($children['assembling_production_inputs'] ?? [] as $in) {
                    if ($isComponent($in->item_id) && $in->finishing) {
                        $b = $bucketOf($in->finishing);
                        $addItemBucket($in->item_id, $b, (float) $in->qty);
                        $addInv((int) $in->warehouse_id, $in->item_id, 0, 0, $b, (float) $in->qty);
                    }
                }
                foreach ($children['assembling_production_outputs'] ?? [] as $o) {
                    $itemStockDeltas[$o->item_id] = ($itemStockDeltas[$o->item_id] ?? 0) - (float) $o->qty;
                }
                break;

            case 'packing':
                $outItemIds = $logs->where('direction', 'OUT')->pluck('item_id')->map(fn($v) => (int) $v)->unique();
                foreach ($logs->where('direction', 'OUT') as $l) {
                    if (!$isComponent($l->item_id)) {
                        continue;
                    }
                    if (!$l->finishing) {
                        $name = Item::where('id', $l->item_id)->value('name');
                        $blockers[] = "Komponen '{$name}' di dokumen packing ini tidak menyimpan pilihan Natural/Warna (dokumen dibuat sebelum fitur pembatalan ada), jadi tidak bisa dibatalkan otomatis.";
                        continue;
                    }
                    $b = $bucketOf($l->finishing);
                    $addItemBucket($l->item_id, $b, (float) $l->qty);
                    $addInv((int) $l->warehouse_id, (int) $l->item_id, 0, 0, $b, (float) $l->qty);
                }
                foreach ($logs->where('direction', 'IN') as $l) {
                    if (!$outItemIds->contains((int) $l->item_id)) {
                        $itemStockDeltas[$l->item_id] = ($itemStockDeltas[$l->item_id] ?? 0) - (float) $l->qty;
                    }
                }
                break;
        }

        $itemNames = Item::whereIn('id', collect($inventoryDeltas)->pluck('item_id')->all())->pluck('name', 'id');
        $whNames   = Warehouse::whereIn('id', collect($inventoryDeltas)->pluck('warehouse_id')->all())->pluck('name', 'id');

        foreach ($inventoryDeltas as $d) {
            if ($d['qty_pcs'] >= -0.0001) {
                continue;
            }
            $invQuery = Inventory::where('warehouse_id', $d['warehouse_id'])->where('item_id', $d['item_id']);
            if ($lock) {
                $invQuery->lockForUpdate();
            }
            $available = (float) ($invQuery->value('qty_pcs') ?? 0);
            $needed    = -$d['qty_pcs'];
            if ($available + 0.0001 < $needed) {
                $itemName = $itemNames[$d['item_id']] ?? "Item #{$d['item_id']}";
                $whName   = $whNames[$d['warehouse_id']] ?? "Gudang #{$d['warehouse_id']}";
                $blockers[] = "Stok '{$itemName}' di {$whName} tinggal " . $this->fmt($available) . ", butuh " . $this->fmt($needed) . " untuk dibatalkan. Kemungkinan barangnya sudah dipakai tahap berikutnya — batalkan transaksi tahap berikutnya dulu.";
            }
        }

        return [
            'stage'            => $stage,
            'header'           => $header,
            'children'         => $children,
            'logs'             => $logs,
            'po'               => $po,
            'inventoryDeltas'  => $inventoryDeltas,
            'itemBucketDeltas' => $itemBucketDeltas,
            'itemStockDeltas'  => $itemStockDeltas,
            'blockers'         => $blockers,
        ];
    }

    private function collectItemIds(Collection $logs, array $children): array
    {
        $ids = $logs->pluck('item_id')->all();
        foreach ($children as $rows) {
            foreach ($rows as $row) {
                if (isset($row->item_id)) {
                    $ids[] = $row->item_id;
                }
            }
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function applyItemChanges(array $bucketDeltas, array $stockDeltas): void
    {
        foreach ($bucketDeltas as $itemId => $deltas) {
            $item = Item::lockForUpdate()->find($itemId);
            if (!$item) {
                continue;
            }
            foreach ($deltas as $bucket => $qty) {
                $item->{$bucket} = max(0, (float) $item->{$bucket} + $qty);
            }
            $item->stock = (float) $item->qty_natural + (float) $item->qty_warna;
            $item->save();
        }

        foreach ($stockDeltas as $itemId => $qty) {
            $item = Item::lockForUpdate()->find($itemId);
            if (!$item) {
                continue;
            }
            $item->stock = max(0, (float) $item->stock + $qty);
            $item->save();
        }
    }

    private function applyInventoryChanges(array $inventoryDeltas, ?int $poId): void
    {
        foreach ($inventoryDeltas as $d) {
            $inv = Inventory::where('warehouse_id', $d['warehouse_id'])
                ->where('item_id', $d['item_id'])
                ->lockForUpdate()
                ->first();

            if (!$inv) {
                if ($d['qty_pcs'] <= 0) {
                    continue;
                }
                Inventory::create([
                    'warehouse_id' => $d['warehouse_id'],
                    'item_id'      => $d['item_id'],
                    'qty_pcs'      => $d['qty_pcs'],
                    'qty_m3'       => max(0, $d['qty_m3']),
                    'qty_natural'  => max(0, $d['qty_natural']),
                    'qty_warna'    => max(0, $d['qty_warna']),
                    'ref_po_id'    => $poId,
                ]);
                continue;
            }

            $inv->qty_pcs     = max(0, (float) $inv->qty_pcs + $d['qty_pcs']);
            $inv->qty_m3      = max(0, (float) $inv->qty_m3 + $d['qty_m3']);
            $inv->qty_natural = max(0, (float) $inv->qty_natural + $d['qty_natural']);
            $inv->qty_warna   = max(0, (float) $inv->qty_warna + $d['qty_warna']);
            $inv->save();
        }
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }
}
