<?php

namespace App\Console\Commands;

use App\Models\DeliveryOrderDetail;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RelinkStaleDeliveryOrderDetails extends Command
{
    protected $signature = 'app:relink-stale-do-details {--dry-run : Tampilkan apa yang akan diubah tanpa menyimpan}';

    protected $description = 'Perbaiki delivery_order_details yang sales_order_detail_id-nya masih menunjuk ke baris sales_order_details yang sudah soft-deleted (terjadi kalau SO diedit setelah DO dibuat, sebelum di-ship — lihat bug ship() yang cuma pakai find() biasa tanpa withTrashed()). Repoint FK ke baris aktif yang sepadan (sales_order_id + item_id sama), lalu recompute quantity_shipped & status SO terkait. Idempotent — aman dijalankan berulang kali.';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $staleDetails = DeliveryOrderDetail::whereNotNull('sales_order_detail_id')
            ->whereHas('salesOrderDetail', fn ($q) => $q->whereNotNull('deleted_at'))
            ->with('salesOrderDetail')
            ->get();

        $this->info("delivery_order_details dengan FK basi (menunjuk SO detail yang sudah soft-deleted): {$staleDetails->count()}");

        if ($staleDetails->isEmpty()) {
            $this->info('Tidak ada yang perlu diperbaiki.');
            return 0;
        }

        $affectedSalesOrderIds = [];

        foreach ($staleDetails as $doDetail) {
            $trashed = $doDetail->salesOrderDetail; // withTrashed() sudah di relasi model
            $activeSibling = SalesOrderDetail::where('sales_order_id', $trashed->sales_order_id)
                ->where('item_id', $trashed->item_id)
                ->first();

            if (!$activeSibling) {
                $this->error("  ! DO detail id={$doDetail->id}: tidak ketemu padanan aktif untuk sales_order_id={$trashed->sales_order_id} item_id={$trashed->item_id} — dilewati, cek manual.");
                continue;
            }

            $this->line("  DO detail id={$doDetail->id}: sales_order_detail_id {$doDetail->sales_order_detail_id} -> {$activeSibling->id} (SO id={$trashed->sales_order_id}, item_id={$trashed->item_id})");

            if (!$dryRun) {
                $doDetail->sales_order_detail_id = $activeSibling->id;
                $doDetail->save();
            }

            $affectedSalesOrderIds[$trashed->sales_order_id] = true;
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Ini masih dry-run. Jalankan tanpa --dry-run untuk menyimpan perubahan (repoint FK + recompute quantity_shipped/status).');
            return 0;
        }

        $this->newLine();
        $this->info('=== Recompute quantity_shipped & status SO yang terdampak ===');

        foreach (array_keys($affectedSalesOrderIds) as $soId) {
            DB::transaction(function () use ($soId) {
                $so = SalesOrder::with('details')->find($soId);
                if (!$so) {
                    return;
                }

                foreach ($so->details as $detail) {
                    $totalShipped = (float) DeliveryOrderDetail::where('sales_order_detail_id', $detail->id)
                        ->sum('quantity_shipped');
                    $detail->quantity_shipped = $totalShipped;
                    $detail->save();
                }

                $so->refresh();

                $doStatuses = DeliveryOrderDetail::whereIn('sales_order_detail_id', $so->details->pluck('id'))
                    ->join('delivery_orders', 'delivery_orders.id', '=', 'delivery_order_details.delivery_order_id')
                    ->pluck('delivery_orders.status')
                    ->unique();

                $allFulfilled = $so->details->every(fn ($d) => (float) $d->quantity <= (float) $d->quantity_shipped);

                if ($doStatuses->contains('DELIVERED')) {
                    $so->status = $allFulfilled ? 'Delivered' : 'Partial Delivered';
                } elseif ($doStatuses->contains('SHIPPED')) {
                    $so->status = $allFulfilled ? 'Shipped' : 'Partial Shipped';
                }

                $so->save();

                $this->info("  SO={$so->so_number}: quantity_shipped direcompute, status -> {$so->status}");
            });
        }

        $this->newLine();
        $this->info('Selesai.');

        return 0;
    }
}
