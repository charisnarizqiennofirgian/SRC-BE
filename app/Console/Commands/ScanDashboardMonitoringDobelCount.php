<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ScanDashboardMonitoringDobelCount extends Command
{
    protected $signature = 'app:scan-dashboard-monitoring-dobel-count {--min-ratio=1.0 : Ambang rasio total-tercatat/target untuk ditampilkan}';

    protected $description = 'Scan READ-ONLY (tidak mengubah data apapun) untuk nemuin kandidat dobel-count qty_produk_jadi di Moulding/Mesin/Rustik Komponen — bandingkan deklarasi eksplisit + estimasi BOM legacy terhadap qty_planned tiap production_order_detail. Hasil scan PERLU DIVERIFIKASI MANUAL satu-satu sebelum dieksekusi (lihat HISTORY.md, kasus CRUISE LOUNGER) — tidak semua kandidat otomatis berarti bug, bisa jadi overproduksi/buffer yang genuine.';

    private const CUTOFF = '2026-07-16 14:24:20';

    public function handle(): int
    {
        $minRatio = (float) $this->option('min-ratio');

        $candidates = array_merge(
            $this->scanLegacyTable('moulding_productions', 'moulding_production_outputs', 'moulding_production_id', 'Moulding'),
            $this->scanLegacyTable('mesin_productions', 'mesin_production_outputs', 'mesin_production_id', 'Mesin'),
            $this->scanSimpleTable('rustik_komponen_productions', 'Rustik Komponen')
        );

        $candidates = array_values(array_filter(
            $candidates,
            fn ($c) => $c['ratio'] === null || $c['ratio'] >= $minRatio
        ));

        usort($candidates, fn ($a, $b) => ($b['ratio'] ?? 0) <=> ($a['ratio'] ?? 0));

        if (empty($candidates)) {
            $this->info('Tidak ada kandidat ditemukan.');
            return 0;
        }

        $this->warn(count($candidates) . ' kandidat ditemukan, diurutkan dari rasio overproduksi tertinggi.');
        $this->warn('INGAT: ini cuma sinyal, PERLU DICEK MANUAL (lihat komponen/BOM/downstream) sebelum disimpulkan bug — bisa jadi genuine overproduksi.');
        $this->newLine();

        foreach ($candidates as $c) {
            $ratioText = $c['ratio'] !== null ? round($c['ratio'], 2) . 'x' : 'n/a (target 0)';
            $this->line("[{$c['stage']}] {$c['item_name']} (SO {$c['so_number']}, target {$c['qty_planned']})");
            $this->line("  detail_id={$c['detail_id']} | total tercatat={$c['total']} | rasio={$ratioText}");
            foreach ($c['rows'] as $r) {
                $this->line("    {$r}");
            }
            $this->newLine();
        }

        return 0;
    }

    private function scanLegacyTable(string $table, string $outputTable, string $fkColumn, string $stageLabel): array
    {
        $rows = DB::table($table)->orderBy('production_order_detail_id')->orderBy('date')->get();
        $groups = $rows->groupBy('production_order_detail_id');

        $candidates = [];
        foreach ($groups as $detailId => $group) {
            if (!$detailId) {
                continue;
            }

            $explicit   = $group->filter(fn ($r) => $r->qty_produk_jadi !== null);
            $legacyNull = $group->filter(fn ($r) => $r->qty_produk_jadi === null && $r->created_at < self::CUTOFF);

            $isCandidate = $explicit->count() >= 2 || ($explicit->count() >= 1 && $legacyNull->count() >= 1);
            if (!$isCandidate) {
                continue;
            }

            $pod = DB::table('production_order_details')->where('id', $detailId)->first();
            if (!$pod) {
                continue;
            }

            $explicitSum = (float) $explicit->sum('qty_produk_jadi');

            $legacyEstimate = 0.0;
            if ($legacyNull->isNotEmpty()) {
                $legacyIds = $legacyNull->pluck('id')->toArray();

                $componentSums = DB::table($outputTable)
                    ->whereIn($fkColumn, $legacyIds)
                    ->select('item_id', DB::raw('SUM(qty) as total_qty'))
                    ->groupBy('item_id')
                    ->get();

                $bomRecipeMap = DB::table('product_boms')
                    ->where('parent_item_id', $pod->item_id)
                    ->pluck('qty', 'child_item_id')
                    ->toArray();

                $impliedUnits = [];
                foreach ($componentSums as $cs) {
                    $qtyPerUnit = $bomRecipeMap[$cs->item_id] ?? null;
                    if ($qtyPerUnit !== null && $qtyPerUnit > 0) {
                        $impliedUnits[] = floor($cs->total_qty / $qtyPerUnit);
                    }
                }
                $legacyEstimate = !empty($impliedUnits) ? (float) min($impliedUnits) : 0.0;
            }

            $candidates[] = $this->buildCandidate(
                $stageLabel,
                $detailId,
                $pod,
                $explicitSum + $legacyEstimate,
                $this->formatRows($group, $legacyEstimate)
            );
        }

        return $candidates;
    }

    private function scanSimpleTable(string $table, string $stageLabel): array
    {
        $rows = DB::table($table)->orderBy('production_order_detail_id')->orderBy('date')->get();
        $groups = $rows->groupBy('production_order_detail_id');

        $candidates = [];
        foreach ($groups as $detailId => $group) {
            if (!$detailId) {
                continue;
            }

            $explicit = $group->filter(fn ($r) => $r->qty_produk_jadi !== null);
            if ($explicit->count() < 2) {
                continue;
            }

            $pod = DB::table('production_order_details')->where('id', $detailId)->first();
            if (!$pod) {
                continue;
            }

            $candidates[] = $this->buildCandidate(
                $stageLabel,
                $detailId,
                $pod,
                (float) $explicit->sum('qty_produk_jadi'),
                $this->formatRows($group, 0.0)
            );
        }

        return $candidates;
    }

    private function buildCandidate(string $stageLabel, int $detailId, object $pod, float $total, array $rows): array
    {
        $item = DB::table('items')->where('id', $pod->item_id)->first(['id', 'name']);
        $po   = DB::table('production_orders')->where('id', $pod->production_order_id)->first();
        $so   = $po ? DB::table('sales_orders')->where('id', $po->sales_order_id)->first(['so_number']) : null;

        $planned = (float) $pod->qty_planned;

        return [
            'stage'       => $stageLabel,
            'detail_id'   => $detailId,
            'item_name'   => $item->name ?? '(item tidak ditemukan)',
            'so_number'   => $so->so_number ?? '-',
            'qty_planned' => $planned,
            'total'       => $total,
            'ratio'       => $planned > 0 ? $total / $planned : null,
            'rows'        => $rows,
        ];
    }

    private function formatRows($group, float $legacyEstimate): array
    {
        $rows = [];
        foreach ($group as $r) {
            $legacyTag = ($r->qty_produk_jadi === null && $r->created_at < self::CUTOFF) ? ' [LEGACY -> ikut diestimasi]' : '';
            $rows[] = "{$r->document_number} | {$r->date} | qty_produk_jadi=" . var_export($r->qty_produk_jadi, true) . $legacyTag;
        }
        if ($legacyEstimate > 0) {
            $rows[] = "  -> estimasi BOM gabungan dari baris legacy: {$legacyEstimate}";
        }
        return $rows;
    }
}
