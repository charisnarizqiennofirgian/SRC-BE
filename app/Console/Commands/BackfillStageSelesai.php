<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\ProductionMonitoringController;
use App\Models\ProductionOrderDetail;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BackfillStageSelesai extends Command
{
    protected $signature = 'app:backfill-stage-selesai
        {--dry-run : Tampilkan apa yang akan ditandai tanpa menyimpan}';

    protected $description = 'Tandai Selesai Moulding/Mesin per produk PO untuk baris dashboard yang sebelumnya tampil "✓ selesai" otomatis (checklist BOM lengkap, sisa 0, barang sudah lanjut tahap berikutnya). Idempotent.';

    private const STAGES = ['moulding', 'mesin', 'ruskomp', 'assembling', 'sanding', 'rustik', 'finishing', 'anyam', 'qc_final', 'packing'];

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $response = app(ProductionMonitoringController::class)->index(new Request());
        $payload  = $response->getData(true);
        if (empty($payload['success'])) {
            $this->error('Gagal mengambil data dashboard: ' . ($payload['message'] ?? '-'));
            return 1;
        }

        $rows  = [];
        $marks = ['moulding' => [], 'mesin' => []];

        foreach ($payload['data'] as $so) {
            foreach ($so['items'] as $item) {
                $detailId = $item['production_order_detail_id'] ?? null;
                if (!$detailId) {
                    continue;
                }
                foreach (['moulding', 'mesin'] as $stage) {
                    if (!empty($item["{$stage}_selesai"]) || !$this->wasAutoPassed($item, $stage)) {
                        continue;
                    }
                    $marks[$stage][$detailId] = true;
                    $rows[] = [$so['so_number'], $item['item_name'], $detailId, ucfirst($stage)];
                }
            }
        }

        if (empty($rows)) {
            $this->info('Tidak ada produk yang perlu ditandai.');
            return 0;
        }

        $this->table(['SO', 'Produk', 'PO Detail ID', 'Tahap'], $rows);

        if (!$dryRun) {
            DB::transaction(function () use ($marks) {
                foreach ($marks as $stage => $ids) {
                    if (empty($ids)) {
                        continue;
                    }
                    ProductionOrderDetail::whereIn('id', array_keys($ids))
                        ->whereNull("{$stage}_completed_at")
                        ->update(["{$stage}_completed_at" => now()]);
                }
            });
        }

        $this->info(($dryRun ? 'Akan ditandai: ' : 'Ditandai: ') . count($rows) . ' baris (Moulding ' . count($marks['moulding']) . ', Mesin ' . count($marks['mesin']) . ').');

        return 0;
    }

    private function wasAutoPassed(array $item, string $stage): bool
    {
        $checklist = $item["{$stage}_bom_checklist"] ?? [];
        if (empty($checklist) || collect($checklist)->contains(fn($c) => empty($c['done']))) {
            return false;
        }
        if ((float) ($item["qty_{$stage}"] ?? 0) > 0) {
            return false;
        }
        if (!empty($item['is_done'])) {
            return true;
        }

        $later = array_slice(self::STAGES, array_search($stage, self::STAGES) + 1);
        foreach ($later as $s) {
            if ((float) ($item["qty_{$s}"] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
