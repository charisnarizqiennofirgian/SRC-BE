<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ProductionCancellation;
use App\Models\Warehouse;
use App\Services\ProductionCancellationService;
use Illuminate\Http\Request;

class ProductionCancellationController extends Controller
{
    public function __construct(private ProductionCancellationService $service)
    {
    }

    public function stages()
    {
        return response()->json([
            'success' => true,
            'data'    => collect(ProductionCancellationService::STAGES)
                ->map(fn($c, $key) => ['value' => $key, 'label' => $c['label']])
                ->values(),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->only(['stage', 'search', 'date_from', 'date_to']);
        $perPage = min(100, max(5, (int) $request->input('per_page', 20)));

        return response()->json([
            'success' => true,
            'data'    => $this->service->listDocuments($filters, $perPage),
        ]);
    }

    public function show(string $documentNumber)
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->preview($documentNumber),
        ]);
    }

    public function cancel(Request $request, string $documentNumber)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $cancellation = $this->service->cancel($documentNumber, $data['reason']);

        return response()->json([
            'success' => true,
            'message' => "Dokumen {$documentNumber} berhasil dibatalkan. Stok sudah dikembalikan.",
            'data'    => ['id' => $cancellation->id],
        ]);
    }

    public function history(Request $request)
    {
        $query = ProductionCancellation::with('user:id,name')->orderByDesc('created_at');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('document_number', 'like', "%{$search}%")
                    ->orWhere('po_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate(min(100, max(5, (int) $request->input('per_page', 20))));

        $allLogs  = $paginator->getCollection()->flatMap(fn($c) => $c->snapshot['logs'] ?? []);
        $itemNames = Item::whereIn('id', $allLogs->pluck('item_id')->unique()->all())->pluck('name', 'id');
        $whNames   = Warehouse::whereIn('id', $allLogs->pluck('warehouse_id')->unique()->all())->pluck('name', 'id');

        $paginator->getCollection()->transform(fn($c) => [
            'id'              => $c->id,
            'document_number' => $c->document_number,
            'stage'           => $c->stage,
            'stage_label'     => ProductionCancellationService::STAGES[$c->stage]['label'] ?? $c->stage,
            'po_number'       => $c->po_number,
            'document_date'   => $c->document_date?->format('Y-m-d'),
            'reason'          => $c->reason,
            'cancelled_by'    => $c->user?->name,
            'cancelled_at'    => $c->created_at?->format('Y-m-d H:i'),
            'lines'           => collect($c->snapshot['logs'] ?? [])->map(fn($l) => [
                'direction'      => $l['direction'] ?? null,
                'is_reject'      => in_array($l['transaction_type'] ?? '', ['REJECT', 'QC_REJECT'], true),
                'item_name'      => $itemNames[$l['item_id'] ?? 0] ?? 'Item #' . ($l['item_id'] ?? '-'),
                'warehouse_name' => $whNames[$l['warehouse_id'] ?? 0] ?? 'Gudang #' . ($l['warehouse_id'] ?? '-'),
                'qty'            => (float) ($l['qty'] ?? 0),
            ])->values(),
        ]);

        return response()->json(['success' => true, 'data' => $paginator]);
    }
}
