<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\SalesOrderPrint;
use Illuminate\Support\Collection;

class SalesOrderPrintService
{
    public const STATUS_BARU = 'baru';
    public const STATUS_BERUBAH = 'berubah';
    public const STATUS_BATAL = 'batal';
    public const STATUS_SUDAH = 'sudah';
    public const STATUS_DITARIK = 'ditarik';

    public const PENDING_STATUSES = [self::STATUS_BARU, self::STATUS_BERUBAH, self::STATUS_BATAL];

    private const NON_FINAL_STATUSES = ['Draft', 'Cancelled'];

    public function isFinal(SalesOrder $so): bool
    {
        return !$so->trashed() && !in_array($so->status, self::NON_FINAL_STATUSES, true);
    }

    public function loadForPrint(SalesOrder $so): SalesOrder
    {
        return $so->load([
            'buyer' => fn ($q) => $q->withTrashed()->select('id', 'name', 'deleted_at'),
            'user:id,name',
            'details' => fn ($q) => $q->select('id', 'sales_order_id', 'item_id', 'item_name', 'item_unit', 'item_code', 'quantity', 'keterangan')->orderBy('id'),
            'details.item' => fn ($q) => $q->select('id', 'name', 'code'),
        ]);
    }

    public function fingerprint(SalesOrder $so): string
    {
        $buyerName = $so->buyer && !$so->buyer->trashed() ? $so->buyer->name : null;

        $payload = [
            'so_number' => $so->so_number,
            'so_date' => $so->so_date?->format('Y-m-d'),
            'buyer' => $buyerName,
            'customer_po_number' => $so->customer_po_number,
            'user' => $so->user?->name,
            'details' => $so->details->sortBy('id')->values()->map(fn ($d) => [
                $d->item_code,
                $d->item?->name ?: $d->item_name,
                (int) (float) $d->quantity,
                $d->item_unit,
                $d->keterangan,
            ])->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function statusFor(SalesOrder $so, ?SalesOrderPrint $last, ?string $currentHash): ?string
    {
        $printed = $last && in_array($last->action, [SalesOrderPrint::ACTION_PRINT, SalesOrderPrint::ACTION_BASELINE], true);

        if ($this->isFinal($so)) {
            if (!$printed) {
                return self::STATUS_BARU;
            }
            return $currentHash === $last->content_hash ? self::STATUS_SUDAH : self::STATUS_BERUBAH;
        }

        if ($printed) {
            return self::STATUS_BATAL;
        }

        return $last && $last->action === SalesOrderPrint::ACTION_WITHDRAWN ? self::STATUS_DITARIK : null;
    }

    public function evaluate(): Collection
    {
        $lastPrints = SalesOrderPrint::with('user:id,name')
            ->whereIn('id', SalesOrderPrint::selectRaw('MAX(id)')->groupBy('sales_order_id'))
            ->get()
            ->keyBy('sales_order_id');

        $orders = SalesOrder::withTrashed()
            ->without('details')
            ->with(['buyer' => fn ($q) => $q->withTrashed()->select('id', 'name')])
            ->withMax('details', 'id')
            ->withCount('details')
            ->where(function ($q) use ($lastPrints) {
                $q->where(function ($qq) {
                    $qq->whereNull('deleted_at')->whereNotIn('status', self::NON_FINAL_STATUSES);
                })->orWhereIn('id', $lastPrints->keys());
            })
            ->get();

        $needHash = $orders->filter(function (SalesOrder $so) use ($lastPrints) {
            $last = $lastPrints->get($so->id);
            if (!$this->isFinal($so) || !$last || $last->action === SalesOrderPrint::ACTION_WITHDRAWN) {
                return false;
            }
            return $so->updated_at >= $last->created_at
                || $this->signatureOf($so->details_max_id, $so->details_count) !== $last->details_signature;
        });

        $hashes = [];
        if ($needHash->isNotEmpty()) {
            $full = SalesOrder::withTrashed()->without('details')->whereIn('id', $needHash->pluck('id'))->get();
            foreach ($full as $so) {
                $hashes[$so->id] = $this->fingerprint($this->loadForPrint($so));
            }
        }

        return $orders->map(function (SalesOrder $so) use ($lastPrints, $hashes) {
            $last = $lastPrints->get($so->id);
            $hash = $hashes[$so->id] ?? $last?->content_hash;
            $status = $this->statusFor($so, $last, $hash);
            if ($status === null) {
                return null;
            }
            return [
                'so' => $so,
                'status' => $status,
                'last' => $last,
            ];
        })->filter()->values();
    }

    private function signatureOf($maxId, $count): string
    {
        return ((int) $maxId) . ':' . ((int) $count);
    }

    public function record(SalesOrder $so, string $action, ?int $userId): SalesOrderPrint
    {
        $hash = null;
        $signature = null;

        if ($action !== SalesOrderPrint::ACTION_WITHDRAWN) {
            $this->loadForPrint($so);
            $hash = $this->fingerprint($so);
            $signature = $this->signatureOf($so->details->max('id'), $so->details->count());
        }

        return SalesOrderPrint::create([
            'sales_order_id' => $so->id,
            'action' => $action,
            'content_hash' => $hash,
            'details_signature' => $signature,
            'user_id' => $userId,
        ]);
    }
}
