<?php

namespace App\Services;

use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StageCompletionService
{
    public const STAGES = [
        'moulding' => [
            'label'        => 'Moulding',
            'table'        => 'moulding_productions',
            'output_table' => 'moulding_production_outputs',
            'output_fk'    => 'moulding_production_id',
            'next_stage'   => 'mesin',
            'stages_before' => [null, '', 'pending', 'sawmill', 'pembahanan', 'moulding'],
        ],
        'mesin' => [
            'label'        => 'Mesin',
            'table'        => 'mesin_productions',
            'output_table' => 'mesin_production_outputs',
            'output_fk'    => 'mesin_production_id',
            'next_stage'   => 'assembly',
            'stages_before' => [null, '', 'pending', 'sawmill', 'pembahanan', 'moulding', 'mesin'],
        ],
    ];

    public function isCompleted(ProductionOrderDetail $detail, string $stage): bool
    {
        return $detail->{"{$stage}_completed_at"} !== null;
    }

    public function ensureNotCompleted(ProductionOrderDetail $detail, string $stage): void
    {
        if ($this->isCompleted($detail, $stage)) {
            $label = self::STAGES[$stage]['label'];
            $name  = $detail->item?->name ?? 'Produk ini';
            throw ValidationException::withMessages([
                'production_order_detail_id' => ["{$name} sudah ditandai Selesai {$label}. Batalkan status selesai dulu kalau masih ada yang perlu diinput."],
            ]);
        }
    }

    public function checklist(ProductionOrderDetail $detail, string $stage): array
    {
        $config = self::STAGES[$stage];

        $productionIds = DB::table($config['table'])
            ->where('production_order_detail_id', $detail->id)
            ->pluck('id');

        $producedItemIds = $productionIds->isEmpty()
            ? collect()
            : DB::table($config['output_table'])
                ->whereIn($config['output_fk'], $productionIds)
                ->distinct()
                ->pluck('item_id')
                ->map(fn($v) => (int) $v);

        $bom = DB::table('product_boms')
            ->leftJoin('items', 'items.id', '=', 'product_boms.child_item_id')
            ->where('product_boms.parent_item_id', $detail->item_id)
            ->get(['product_boms.child_item_id', 'items.name']);

        $missing = $bom
            ->reject(fn($b) => $producedItemIds->contains((int) $b->child_item_id))
            ->map(fn($b) => $b->name ?? "Item #{$b->child_item_id}")
            ->values()
            ->all();

        return [
            'has_transactions' => $productionIds->isNotEmpty(),
            'bom_total'        => $bom->count(),
            'bom_done'         => $bom->count() - count($missing),
            'missing'          => $missing,
        ];
    }

    public function summary(ProductionOrderDetail $detail, string $stage): array
    {
        $checklist = $this->checklist($detail, $stage);
        $by        = $detail->{"{$stage}CompletedBy"};

        return [
            "{$stage}_selesai"       => $this->isCompleted($detail, $stage),
            "{$stage}_selesai_at"    => $detail->{"{$stage}_completed_at"}?->format('d/m/Y H:i'),
            "{$stage}_selesai_by"    => $by?->name,
            "{$stage}_bom_total"     => $checklist['bom_total'],
            "{$stage}_bom_done"      => $checklist['bom_done'],
            "{$stage}_bom_missing"   => $checklist['missing'],
            "{$stage}_has_transaksi" => $checklist['has_transactions'],
        ];
    }

    public function markCompleted(ProductionOrderDetail $detail, string $stage, ?int $userId): array
    {
        $label = self::STAGES[$stage]['label'];

        return DB::transaction(function () use ($detail, $stage, $userId, $label) {
            $detail = ProductionOrderDetail::with('item')->lockForUpdate()->findOrFail($detail->id);
            $this->ensureNotCompleted($detail, $stage);

            $checklist = $this->checklist($detail, $stage);
            if (!$checklist['has_transactions']) {
                throw ValidationException::withMessages([
                    'production_order_detail_id' => ["Belum ada transaksi {$label} untuk produk ini, jadi belum bisa ditandai selesai."],
                ]);
            }
            if (!empty($checklist['missing'])) {
                throw ValidationException::withMessages([
                    'production_order_detail_id' => [
                        "Checklist BOM {$label} belum lengkap ({$checklist['bom_done']}/{$checklist['bom_total']}). Komponen yang belum: " . implode(', ', $checklist['missing']),
                    ],
                ]);
            }

            $detail->update([
                "{$stage}_completed_at" => now(),
                "{$stage}_completed_by" => $userId,
            ]);

            $poAdvanced = $this->advanceProductionOrderIfAllCompleted($detail->production_order_id, $stage);

            return ['detail' => $detail->fresh('item'), 'po_advanced' => $poAdvanced];
        });
    }

    public function unmarkCompleted(ProductionOrderDetail $detail, string $stage): ProductionOrderDetail
    {
        if (!$this->isCompleted($detail, $stage)) {
            throw ValidationException::withMessages([
                'production_order_detail_id' => ['Produk ini belum ditandai selesai ' . self::STAGES[$stage]['label'] . '.'],
            ]);
        }

        $detail->update([
            "{$stage}_completed_at" => null,
            "{$stage}_completed_by" => null,
        ]);

        return $detail->fresh('item');
    }

    private function advanceProductionOrderIfAllCompleted(int $productionOrderId, string $stage): bool
    {
        $config = self::STAGES[$stage];
        $po     = ProductionOrder::find($productionOrderId);
        if (!$po || $po->status === 'completed') {
            return false;
        }

        $pending = ProductionOrderDetail::where('production_order_id', $po->id)
            ->whereNull("{$stage}_completed_at")
            ->exists();
        if ($pending || !in_array($po->current_stage, $config['stages_before'], true)) {
            return false;
        }

        $po->update(['current_stage' => $config['next_stage'], 'status' => 'in_progress']);

        return true;
    }
}
