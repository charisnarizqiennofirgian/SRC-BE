<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\StockOpname;
use App\Models\StockOpnameDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockOpnameService
{
    const EPS = 0.00001;

    public function snapshotWarehouse(StockOpname $opname): int
    {
        $inventories = Inventory::with('item.category')
            ->where('warehouse_id', $opname->warehouse_id)
            ->where(function ($q) {
                $q->where('qty_pcs', '>', 0)
                    ->orWhere('qty_natural', '>', 0)
                    ->orWhere('qty_warna', '>', 0);
            })
            ->whereHas('item')
            ->get()
            ->sortBy([
                fn ($a, $b) => strcmp($a->item->category?->name ?? '', $b->item->category?->name ?? ''),
                fn ($a, $b) => strnatcasecmp($a->item->name ?? '', $b->item->name ?? ''),
                fn ($a, $b) => strcmp($a->grade ?? '', $b->grade ?? ''),
            ]);

        $rows = $inventories->map(fn (Inventory $inv) => [
            'stock_opname_id'    => $opname->id,
            'item_id'            => $inv->item_id,
            'grade'              => $inv->grade,
            'row_type'           => StockOpnameDetail::rowTypeForCategory($inv->item->category?->name),
            'is_manual'          => false,
            'system_qty_pcs'     => (float) $inv->qty_pcs,
            'system_qty_natural' => (float) $inv->qty_natural,
            'system_qty_warna'   => (float) $inv->qty_warna,
            'system_qty_m3'      => (float) $inv->qty_m3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        foreach ($rows->chunk(500) as $chunk) {
            StockOpnameDetail::insert($chunk->values()->all());
        }

        return $rows->count();
    }

    public function currentStockMap(int $warehouseId, array $itemIds): array
    {
        $map = [];
        foreach (array_chunk(array_unique($itemIds), 1000) as $chunk) {
            Inventory::where('warehouse_id', $warehouseId)
                ->whereIn('item_id', $chunk)
                ->get(['item_id', 'grade', 'qty_pcs', 'qty_natural', 'qty_warna', 'qty_m3'])
                ->each(function ($inv) use (&$map) {
                    $map[$inv->item_id . '|' . ($inv->grade ?? '')] = $inv;
                });
        }

        return $map;
    }

    public function post(int $opnameId, int $userId): array
    {
        return DB::transaction(function () use ($opnameId, $userId) {
            $opname = StockOpname::lockForUpdate()->findOrFail($opnameId);

            if (!$opname->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => ['Stok opname ini sudah diposting.'],
                ]);
            }

            $details = $opname->details()
                ->whereNotNull('real_qty_pcs')
                ->orderBy('id')
                ->get();

            $itemsWithRows = Inventory::whereIn('item_id', $details->pluck('item_id')->unique())
                ->distinct()
                ->pluck('item_id')
                ->flip()
                ->all();

            $summary = ['counted' => $details->count(), 'changed' => 0, 'plus' => 0, 'minus' => 0];

            foreach ($details as $detail) {
                $changed = $this->postDetail($opname, $detail, $userId, $itemsWithRows);
                $itemsWithRows[$detail->item_id] = true;

                if ($changed) {
                    $summary['changed']++;
                    if ($detail->diff_qty_pcs > self::EPS) {
                        $summary['plus']++;
                    } elseif ($detail->diff_qty_pcs < -self::EPS) {
                        $summary['minus']++;
                    }
                }
            }

            $opname->update([
                'status'    => StockOpname::STATUS_POSTED,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            Item::clearMaterialsCache();

            return $summary;
        });
    }

    private function postDetail(StockOpname $opname, StockOpnameDetail $detail, int $userId, array $itemsWithRows): bool
    {
        $item = Item::lockForUpdate()->findOrFail($detail->item_id);
        $isKomponen = $detail->row_type === StockOpnameDetail::TYPE_KOMPONEN;

        $inventory = Inventory::where('warehouse_id', $opname->warehouse_id)
            ->where('item_id', $detail->item_id)
            ->where('grade_key', $detail->grade ?? '')
            ->lockForUpdate()
            ->first();

        $curPcs    = (float) ($inventory->qty_pcs ?? 0);
        $curM3     = (float) ($inventory->qty_m3 ?? 0);
        $curNat    = (float) ($inventory->qty_natural ?? 0);
        $curWarna  = (float) ($inventory->qty_warna ?? 0);

        $newPcs   = (float) $detail->real_qty_pcs;
        $newNat   = $isKomponen ? (float) $detail->real_qty_natural : $curNat;
        $newWarna = $isKomponen ? (float) $detail->real_qty_warna : $curWarna;

        $diff = $newPcs - $curPcs;
        $breakdownChanged = $isKomponen
            && (abs($newNat - $curNat) > self::EPS || abs($newWarna - $curWarna) > self::EPS);

        if (abs($diff) <= self::EPS && !$breakdownChanged) {
            $detail->update([
                'posted_system_qty_pcs' => $curPcs,
                'diff_qty_pcs'          => 0,
                'diff_qty_m3'           => 0,
            ]);

            return false;
        }

        $newM3 = $curM3;
        if (abs($diff) > self::EPS) {
            $perPcs = $this->m3PerPcs($item, $curPcs, $curM3);
            $newM3 = $perPcs > 0 ? $newPcs * $perPcs : ($newPcs <= 0 ? 0 : $curM3);
        }
        $diffM3 = $newM3 - $curM3;

        if (!$inventory) {
            $inventory = Inventory::create([
                'warehouse_id' => $opname->warehouse_id,
                'item_id'      => $detail->item_id,
                'grade'        => $detail->grade,
                'qty_pcs'      => 0,
                'qty_m3'       => 0,
                'qty_natural'  => 0,
                'qty_warna'    => 0,
            ]);
        }

        $inventory->update([
            'qty_pcs'     => $newPcs,
            'qty_m3'      => $newM3,
            'qty_natural' => $newNat,
            'qty_warna'   => $newWarna,
        ]);

        if (abs($diff) > self::EPS) {
            $notes = 'Stok opname ' . $opname->opname_number . ': sistem ' . $this->fmt($curPcs) . ' → fisik ' . $this->fmt($newPcs);
            if ($detail->notes) {
                $notes .= ' (' . $detail->notes . ')';
            }

            InventoryLog::create([
                'date'             => $opname->opname_date,
                'time'             => now()->toTimeString(),
                'item_id'          => $detail->item_id,
                'warehouse_id'     => $opname->warehouse_id,
                'qty'              => abs($diff),
                'qty_m3'           => abs($diffM3),
                'direction'        => $diff > 0 ? 'IN' : 'OUT',
                'transaction_type' => 'OPNAME',
                'reference_type'   => 'stock_opname',
                'reference_id'     => $opname->id,
                'reference_number' => $opname->opname_number,
                'notes'            => $notes,
                'grade'            => $detail->grade,
                'user_id'          => $userId,
            ]);
        }

        $hadRows = isset($itemsWithRows[$detail->item_id]);

        if ($isKomponen) {
            if (!$hadRows) {
                $item->qty_natural = $newNat;
                $item->qty_warna   = $newWarna;
            } else {
                [$estNat, $estWarna] = $this->estimateBreakdown($item, $curPcs, $curNat, $curWarna);
                $item->qty_natural = max(0, (float) $item->qty_natural + ($newNat - $estNat));
                $item->qty_warna   = max(0, (float) $item->qty_warna + ($newWarna - $estWarna));
            }
            $item->stock = (float) $item->qty_natural + (float) $item->qty_warna;
        } else {
            $item->stock = $hadRows ? max(0, (float) $item->stock + $diff) : $newPcs;
        }
        $item->save();

        $detail->update([
            'posted_system_qty_pcs' => $curPcs,
            'diff_qty_pcs'          => $diff,
            'diff_qty_m3'           => $diffM3,
        ]);

        return true;
    }

    private function estimateBreakdown(Item $item, float $curPcs, float $curNat, float $curWarna): array
    {
        if ($curPcs <= self::EPS || abs(($curNat + $curWarna) - $curPcs) <= self::EPS) {
            return [$curNat, $curWarna];
        }

        $globalTotal = (float) $item->qty_natural + (float) $item->qty_warna;
        $ratioNat = $globalTotal > self::EPS ? (float) $item->qty_natural / $globalTotal : 1;
        $estNat = $curPcs * $ratioNat;

        return [$estNat, $curPcs - $estNat];
    }

    private function m3PerPcs(Item $item, float $curPcs, float $curM3): float
    {
        if ($curPcs > self::EPS && $curM3 > 0) {
            return $curM3 / $curPcs;
        }

        $specs = is_array($item->specifications) ? $item->specifications : [];
        if (!empty($specs['m3_per_pcs']) && (float) $specs['m3_per_pcs'] > 0) {
            return (float) $specs['m3_per_pcs'];
        }

        foreach (['volume_m3', 'kubikasi'] as $field) {
            $value = (float) $item->getRawOriginal($field);
            if ($value > 0) {
                return $value;
            }
        }

        return 0;
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, ',', '.'), '0'), ',');
    }
}
