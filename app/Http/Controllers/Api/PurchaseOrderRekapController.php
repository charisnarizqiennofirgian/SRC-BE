<?php

namespace App\Http\Controllers\Api;

use App\Exports\PurchaseOrderRekapSupplierExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class PurchaseOrderRekapController extends Controller
{
    const TYPE_PERMISSIONS = [
        'operasional' => 'pembelian-operasional',
        'karton'      => 'pembelian-karton',
    ];

    public function options(Request $request)
    {
        $types = $this->allowedTypes($request);

        $suppliers = DB::table('purchase_orders as p')
            ->join('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->whereNull('p.deleted_at')
            ->whereIn('p.type', $types)
            ->select('s.id', 's.name')
            ->distinct()
            ->orderBy('s.name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => ['suppliers' => $suppliers, 'types' => $types],
        ]);
    }

    public function index(Request $request)
    {
        $rows = $this->buildQuery($request)->get()->map(fn ($row) => $this->formatRow($row));

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function export(Request $request)
    {
        $rows = $this->buildQuery($request)->get()->map(fn ($row) => $this->formatRow($row));

        $supplierName = $request->filled('supplier_id')
            ? (DB::table('suppliers')->where('id', $request->supplier_id)->value('name') ?? 'Supplier')
            : 'Semua Supplier';
        $fileName = 'Rekap-PO-' . trim(preg_replace('/[^A-Za-z0-9]+/', '-', $supplierName), '-') . '-' . now()->format('Ymd') . '.xlsx';

        return Excel::download(new PurchaseOrderRekapSupplierExport($rows, $this->filterSummary($request, $supplierName)), $fileName);
    }

    private function allowedTypes(Request $request): array
    {
        $user = $request->user();

        return array_values(array_keys(array_filter(
            self::TYPE_PERMISSIONS,
            fn ($permission) => $user && $user->can($permission)
        )));
    }

    private function buildQuery(Request $request)
    {
        $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'type'        => ['nullable', 'in:operasional,karton'],
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date'],
            'outstanding' => ['nullable', 'boolean'],
            'search'      => ['nullable', 'string', 'max:100'],
        ]);

        $types = $this->allowedTypes($request);
        if ($request->filled('type')) {
            $types = array_values(array_intersect($types, [$request->type]));
        }

        $received = DB::table('goods_receipt_details as grd')
            ->join('goods_receipts as gr', 'gr.id', '=', 'grd.goods_receipt_id')
            ->whereNull('gr.deleted_at')
            ->groupBy('grd.purchase_order_detail_id')
            ->select('grd.purchase_order_detail_id', DB::raw('SUM(grd.quantity_received) as qty_received'), DB::raw('MAX(gr.receipt_date) as last_receipt_date'));

        $query = DB::table('purchase_order_details as d')
            ->join('purchase_orders as p', 'p.id', '=', 'd.purchase_order_id')
            ->join('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->leftJoin('items as i', 'i.id', '=', 'd.item_id')
            ->leftJoin('units as u', 'u.id', '=', 'i.unit_id')
            ->leftJoinSub($received, 'rcv', 'rcv.purchase_order_detail_id', '=', 'd.id')
            ->whereNull('p.deleted_at')
            ->where('p.status', '!=', 'Cancelled')
            ->whereIn('p.type', $types)
            ->select(
                'd.id',
                'p.po_number',
                'p.type',
                'p.status',
                'p.order_date',
                'p.delivery_date',
                'p.currency',
                's.name as supplier_name',
                'i.code as item_code',
                'i.name as item_name',
                'u.name as unit_name',
                'd.quantity_ordered',
                'd.price',
                'd.subtotal',
                DB::raw('COALESCE(rcv.qty_received, 0) as qty_received'),
                'rcv.last_receipt_date'
            )
            ->orderBy('s.name')
            ->orderBy('p.order_date')
            ->orderBy('p.po_number')
            ->orderBy('d.id');

        if ($request->filled('supplier_id')) {
            $query->where('p.supplier_id', $request->supplier_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('p.order_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('p.order_date', '<=', $request->date_to);
        }
        if ($request->boolean('outstanding')) {
            $query->whereRaw('d.quantity_ordered - COALESCE(rcv.qty_received, 0) > 0.0001');
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('p.po_number', 'like', "%{$search}%")
                    ->orWhere('i.name', 'like', "%{$search}%")
                    ->orWhere('i.code', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    private function formatRow($row): array
    {
        $ordered = (float) $row->quantity_ordered;
        $received = (float) $row->qty_received;

        return [
            'id'                => $row->id,
            'po_number'         => $row->po_number,
            'type'              => $row->type,
            'status'            => $row->status,
            'order_date'        => $row->order_date,
            'delivery_date'     => $row->delivery_date,
            'currency'          => $row->currency ?: 'IDR',
            'supplier_name'     => $row->supplier_name,
            'item_code'         => $row->item_code,
            'item_name'         => $row->item_name,
            'unit_name'         => $row->unit_name,
            'qty_ordered'       => $ordered,
            'price'             => (float) $row->price,
            'subtotal'          => (float) $row->subtotal,
            'qty_received'      => $received,
            'qty_remaining'     => max(0, $ordered - $received),
            'last_receipt_date' => $row->last_receipt_date,
        ];
    }

    private function filterSummary(Request $request, string $supplierName): array
    {
        $types = $request->filled('type') ? ucfirst($request->type) : 'Operasional & Karton Box';
        $period = ($request->date_from || $request->date_to)
            ? (($request->date_from ?: '...') . ' s/d ' . ($request->date_to ?: '...'))
            : 'Semua tanggal';

        return [
            'Supplier' => $supplierName,
            'Jenis'    => $types === 'Karton' ? 'Karton Box' : $types,
            'Periode PO' => $period,
            'Filter'   => $request->boolean('outstanding') ? 'Hanya yang masih ada sisa' : 'Semua baris',
        ];
    }
}
