<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\SalesOrderPrint;
use App\Services\SalesOrderPrintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DokumenSalesOrderController extends Controller
{
    public function __construct(private SalesOrderPrintService $service)
    {
    }

    private function toRow(array $entry): array
    {
        $so = $entry['so'];
        $last = $entry['last'];

        return [
            'id' => $so->id,
            'so_number' => $so->so_number,
            'no_pi' => $so->no_pi,
            'buyer_name' => $so->buyer?->name,
            'customer_po_number' => $so->customer_po_number,
            'so_date' => $so->so_date?->format('Y-m-d'),
            'delivery_date' => $so->delivery_date?->format('Y-m-d'),
            'so_status' => $so->status,
            'is_deleted' => $so->trashed(),
            'print_status' => $entry['status'],
            'last_action' => $last?->action,
            'last_action_at' => $last?->created_at?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'last_action_by' => $last?->user?->name,
        ];
    }

    public function index(Request $request)
    {
        $filter = $request->input('filter', 'perlu');
        $search = strtolower(trim((string) $request->input('search', '')));
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $page = max((int) $request->input('page', 1), 1);

        $entries = $this->service->evaluate();

        $counts = [
            'perlu' => $entries->whereIn('status', SalesOrderPrintService::PENDING_STATUSES)->count(),
            'semua' => $entries->count(),
        ];

        if ($filter === 'perlu') {
            $entries = $entries->whereIn('status', SalesOrderPrintService::PENDING_STATUSES);
        }

        if ($search !== '') {
            $entries = $entries->filter(function ($e) use ($search) {
                $so = $e['so'];
                return str_contains(strtolower((string) $so->so_number), $search)
                    || str_contains(strtolower((string) $so->no_pi), $search)
                    || str_contains(strtolower((string) $so->customer_po_number), $search)
                    || str_contains(strtolower((string) $so->buyer?->name), $search);
            });
        }

        $entries = $entries->sortByDesc(fn ($e) => sprintf('%s-%010d', $e['so']->so_date?->format('Y-m-d') ?? '0000-00-00', $e['so']->id))->values();

        $total = $entries->count();
        $rows = $entries->forPage($page, $perPage)->map(fn ($e) => $this->toRow($e))->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'counts' => $counts,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max((int) ceil($total / $perPage), 1),
                'from' => $total ? ($page - 1) * $perPage + 1 : 0,
                'to' => min($page * $perPage, $total),
            ],
        ]);
    }

    public function pending()
    {
        $entries = $this->service->evaluate()
            ->whereIn('status', SalesOrderPrintService::PENDING_STATUSES)
            ->values();

        return response()->json([
            'success' => true,
            'count' => $entries->count(),
            'data' => $entries->map(fn ($e) => [
                'id' => $e['so']->id,
                'so_number' => $e['so']->so_number,
                'buyer_name' => $e['so']->buyer?->name,
                'print_status' => $e['status'],
            ])->values(),
        ]);
    }

    public function show(string $id)
    {
        $so = SalesOrder::withTrashed()->without('details')->findOrFail($id);
        $this->service->loadForPrint($so);

        $data = [
            'id' => $so->id,
            'so_number' => $so->so_number,
            'so_date' => $so->so_date?->format('Y-m-d'),
            'customer_po_number' => $so->customer_po_number,
            'status' => $so->status,
            'is_final' => $this->service->isFinal($so),
            'buyer' => $so->buyer && !$so->buyer->trashed() ? ['id' => $so->buyer->id, 'name' => $so->buyer->name] : null,
            'user' => $so->user ? ['id' => $so->user->id, 'name' => $so->user->name] : null,
            'details' => $so->details->map(fn ($d) => [
                'id' => $d->id,
                'item_code' => $d->item_code,
                'item_name' => $d->item_name,
                'item_unit' => $d->item_unit,
                'quantity' => $d->quantity,
                'keterangan' => $d->keterangan,
                'item' => $d->item ? ['id' => $d->item->id, 'name' => $d->item->name, 'code' => $d->item->code] : null,
            ])->values(),
        ];

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function printed(string $id)
    {
        $so = SalesOrder::without('details')->findOrFail($id);

        if (!$this->service->isFinal($so)) {
            return response()->json([
                'success' => false,
                'message' => 'SO ini belum final (masih Draft / dibatalkan), tidak bisa dicatat sebagai dicetak.',
            ], 422);
        }

        $log = DB::transaction(fn () => $this->service->record($so, SalesOrderPrint::ACTION_PRINT, Auth::id()));

        return response()->json([
            'success' => true,
            'message' => 'Cetak SO tercatat.',
            'data' => ['printed_at' => $log->created_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i')],
        ]);
    }

    public function withdrawn(string $id)
    {
        $so = SalesOrder::withTrashed()->without('details')->findOrFail($id);
        $last = SalesOrderPrint::where('sales_order_id', $so->id)->latest('id')->first();

        if ($this->service->statusFor($so, $last, $last?->content_hash) !== SalesOrderPrintService::STATUS_BATAL) {
            return response()->json([
                'success' => false,
                'message' => 'SO ini tidak dalam status batal, tidak perlu ditandai ditarik.',
            ], 422);
        }

        $this->service->record($so, SalesOrderPrint::ACTION_WITHDRAWN, Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Kertas SO ditandai sudah ditarik dari lapangan.',
        ]);
    }

    public function history(string $id)
    {
        $logs = SalesOrderPrint::with('user:id,name')
            ->where('sales_order_id', $id)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'action' => $l->action,
                'user_name' => $l->user?->name,
                'created_at' => $l->created_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            ]);

        return response()->json(['success' => true, 'data' => $logs]);
    }
}
