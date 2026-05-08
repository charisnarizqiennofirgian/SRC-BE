<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestDetail;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseRequestController extends Controller
{
    // GET /purchase-requests
    public function index(Request $request)
    {
        try {
            $query = PurchaseRequest::with([
                'salesOrder:id,so_number',
                'requestedBy:id,name',
            ])->withCount('details')->latest();

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('pr_number', 'like', "%{$search}%")
                      ->orWhereHas('salesOrder', fn($q2) => $q2->where('so_number', 'like', "%{$search}%"));
                });
            }

            $perPage = $request->input('per_page', 15);
            $prs = $query->paginate($perPage);

            return response()->json([
                'data' => $prs->map(fn($pr) => [
                    'id'            => $pr->id,
                    'pr_number'     => $pr->pr_number,
                    'so_id'         => $pr->so_id,
                    'sales_order'   => $pr->salesOrder ? ['so_number' => $pr->salesOrder->so_number] : null,
                    'requested_by'  => $pr->requestedBy ? ['name' => $pr->requestedBy->name] : null,
                    'deadline'      => $pr->deadline?->toDateString(),
                    'status'        => $pr->status,
                    'details_count' => $pr->details_count,
                    'created_at'    => $pr->created_at,
                ]),
                'meta' => [
                    'total'        => $prs->total(),
                    'current_page' => $prs->currentPage(),
                    'per_page'     => $prs->perPage(),
                    'last_page'    => $prs->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error index PR: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan.'], 500);
        }
    }

    // POST /purchase-requests
    public function store(Request $request)
    {
        $request->validate([
            'so_id'    => 'nullable|exists:sales_orders,id',
            'deadline' => 'required|date',
            'notes'    => 'nullable|string',
            'details'  => 'required|array|min:1',
            'details.*.item_id'      => 'required|exists:items,id',
            'details.*.qty_requested'=> 'required|numeric|min:0.001',
            'details.*.notes'        => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Generate PR number
            $count = PurchaseRequest::whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count();
            $prNumber = 'PR-' . now()->format('Ym') . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);

            $pr = PurchaseRequest::create([
                'pr_number'    => $prNumber,
                'so_id'        => $request->so_id,
                'requested_by' => Auth::id(),
                'deadline'     => $request->deadline,
                'notes'        => $request->notes,
                'status'       => 'draft',
            ]);

            foreach ($request->details as $detail) {
                PurchaseRequestDetail::create([
                    'purchase_request_id' => $pr->id,
                    'item_id'             => $detail['item_id'],
                    'qty_requested'       => $detail['qty_requested'],
                    'notes'               => $detail['notes'] ?? null,
                ]);
            }

            DB::commit();

            $pr->load(['salesOrder:id,so_number', 'requestedBy:id,name', 'details.item:id,name,code']);

            return response()->json([
                'success' => true,
                'message' => "PR {$prNumber} berhasil dibuat.",
                'data'    => $pr,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error store PR: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal membuat PR.'], 500);
        }
    }

    // GET /purchase-requests/{id}
    public function show($id)
    {
        try {
            $pr = PurchaseRequest::with([
                'salesOrder:id,so_number,buyer_id',
                'salesOrder.buyer:id,name',
                'requestedBy:id,name',
                'details.item:id,name,code,unit_id',
                'details.item.unit:id,name',
            ])->findOrFail($id);

            return response()->json(['success' => true, 'data' => $pr]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'PR tidak ditemukan.'], 404);
        }
    }

    // PUT /purchase-requests/{id}
    public function update(Request $request, $id)
    {
        $pr = PurchaseRequest::findOrFail($id);

        if (!in_array($pr->status, ['draft'])) {
            return response()->json([
                'success' => false,
                'message' => 'PR hanya bisa diedit saat status Draft.',
            ], 422);
        }

        $request->validate([
            'so_id'    => 'nullable|exists:sales_orders,id',
            'deadline' => 'required|date',
            'notes'    => 'nullable|string',
            'details'  => 'required|array|min:1',
            'details.*.item_id'       => 'required|exists:items,id',
            'details.*.qty_requested' => 'required|numeric|min:0.001',
            'details.*.notes'         => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $pr->update([
                'so_id'    => $request->so_id,
                'deadline' => $request->deadline,
                'notes'    => $request->notes,
            ]);

            // Hapus detail lama, insert baru
            $pr->details()->delete();
            foreach ($request->details as $detail) {
                PurchaseRequestDetail::create([
                    'purchase_request_id' => $pr->id,
                    'item_id'             => $detail['item_id'],
                    'qty_requested'       => $detail['qty_requested'],
                    'notes'               => $detail['notes'] ?? null,
                ]);
            }

            DB::commit();
            $pr->load(['salesOrder:id,so_number', 'requestedBy:id,name', 'details.item:id,name,code']);

            return response()->json([
                'success' => true,
                'message' => 'PR berhasil diperbarui.',
                'data'    => $pr,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Gagal update PR.'], 500);
        }
    }

    // POST /purchase-requests/{id}/submit
    public function submit($id)
    {
        $pr = PurchaseRequest::findOrFail($id);

        if ($pr->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya PR berstatus Draft yang bisa disubmit.',
            ], 422);
        }

        $pr->update(['status' => 'submitted']);

        return response()->json([
            'success' => true,
            'message' => "PR {$pr->pr_number} berhasil disubmit ke Tim Pembelian.",
        ]);
    }

    // POST /purchase-requests/{id}/cancel
    public function cancel($id)
    {
        $pr = PurchaseRequest::findOrFail($id);

        if (in_array($pr->status, ['completed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'PR sudah selesai atau dibatalkan.',
            ], 422);
        }

        $pr->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => "PR {$pr->pr_number} berhasil dibatalkan.",
        ]);
    }

    // DELETE /purchase-requests/{id}
    public function destroy($id)
    {
        $pr = PurchaseRequest::findOrFail($id);

        if ($pr->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya PR berstatus Draft yang bisa dihapus.',
            ], 422);
        }

        $pr->details()->delete();
        $pr->delete();

        return response()->json([
            'success' => true,
            'message' => "PR {$pr->pr_number} berhasil dihapus.",
        ]);
    }

    // POST /purchase-requests/{id}/convert-to-po
    public function convertToPO(Request $request, $id)
    {
        $pr = PurchaseRequest::with('details.item')->findOrFail($id);

        if (!in_array($pr->status, ['submitted', 'in_progress'])) {
            return response()->json([
                'success' => false,
                'message' => 'PR harus berstatus Submitted atau In Progress untuk dikonvert ke PO.',
            ], 422);
        }

        $request->validate([
            'supplier_id'    => 'required|exists:suppliers,id',
            'order_date'     => 'required|date',
            'ppn_percentage' => 'nullable|numeric|min:0',
            'notes'          => 'nullable|string',
            'details'        => 'required|array|min:1',
            'details.*.purchase_request_detail_id' => 'required|exists:purchase_request_details,id',
            'details.*.item_id'   => 'required|exists:items,id',
            'details.*.quantity'  => 'required|numeric|min:0.001',
            'details.*.price'     => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            // Generate PO number
            $count = PurchaseOrder::whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count();
            $poNumber = 'PO-' . now()->format('Ym') . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);

            // Hitung subtotal dari details
            $subtotal = collect($request->details)->sum(fn($d) => $d['quantity'] * $d['price']);
            $ppnRate  = $request->ppn_percentage ?? 12;
            $actualPpnRate = ($ppnRate == 11.12) ? 11 : $ppnRate;
            $ppnAmount  = $subtotal * ($actualPpnRate / 100);
            $grandTotal = $subtotal + $ppnAmount;

            // Tentukan type dari item pertama
            $firstItemId = $request->details[0]['item_id'];
            $firstItem   = \App\Models\Item::with('category')->find($firstItemId);
            $catName     = strtolower($firstItem?->category?->name ?? '');
            $type = 'operasional';
            if (str_contains($catName, 'karton')) $type = 'karton';
            elseif (str_contains($catName, 'kayu')) $type = 'kayu';

            $po = PurchaseOrder::create([
                'po_number'      => $poNumber,
                'supplier_id'    => $request->supplier_id,
                'order_date'     => $request->order_date,
                'delivery_date'  => $request->delivery_date ?? null,
                'ppn_percentage' => $ppnRate,
                'ppn_amount'     => $ppnAmount,
                'subtotal'       => $subtotal,
                'grand_total'    => $grandTotal,
                'notes'          => $request->notes,
                'status'         => 'Open',
                'type'           => $type,
            ]);

            foreach ($request->details as $detail) {
                $po->details()->create([
                    'item_id'         => $detail['item_id'],
                    'quantity_ordered' => $detail['quantity'],
                    'price'           => $detail['price'],
                    'subtotal'        => $detail['quantity'] * $detail['price'],
                ]);

                // Update qty_approved di PR detail
                PurchaseRequestDetail::where('id', $detail['purchase_request_detail_id'])
                    ->update(['qty_approved' => $detail['quantity']]);
            }

            // Update status PR
            $pr->update(['status' => 'completed']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "PR {$pr->pr_number} berhasil dikonvert ke PO {$poNumber}.",
                'data'    => ['po_number' => $poNumber, 'po_id' => $po->id],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error convert PR to PO: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal konvert ke PO.'], 500);
        }
    }
}