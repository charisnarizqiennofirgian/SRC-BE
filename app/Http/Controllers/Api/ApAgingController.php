<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseBill;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ApAgingController extends Controller
{
    public function index(Request $request)
    {
        try {
            $asOf = $request->filled('as_of_date')
                ? Carbon::parse($request->as_of_date)->endOfDay()
                : Carbon::today();

            $query = PurchaseBill::with('supplier')
                ->where('status', 'Posted')
                ->where('remaining_amount', '>', 0)
                ->where('bill_date', '<=', $asOf);

            if ($request->filled('supplier_id')) {
                $query->where('supplier_id', $request->supplier_id);
            }

            $bills = $query->orderBy('due_date')->get();

            $buckets = [
                'current' => ['label' => 'Belum Jatuh Tempo', 'total' => 0, 'count' => 0],
                '1_30'    => ['label' => '1 - 30 Hari',       'total' => 0, 'count' => 0],
                '31_60'   => ['label' => '31 - 60 Hari',      'total' => 0, 'count' => 0],
                '61_90'   => ['label' => '61 - 90 Hari',      'total' => 0, 'count' => 0],
                'over_90' => ['label' => '> 90 Hari',         'total' => 0, 'count' => 0],
            ];

            $bySupplier = [];
            $detailRows = [];

            foreach ($bills as $bill) {
                $dueDate     = Carbon::parse($bill->due_date);
                $daysOverdue = $dueDate->isPast() ? (int) $dueDate->diffInDays($asOf) : 0;
                $isOverdue   = $asOf->gt($dueDate);

                $bucket = match (true) {
                    !$isOverdue        => 'current',
                    $daysOverdue <= 30 => '1_30',
                    $daysOverdue <= 60 => '31_60',
                    $daysOverdue <= 90 => '61_90',
                    default            => 'over_90',
                };

                $remaining   = (float) $bill->remaining_amount;
                $supplierId  = $bill->supplier_id;
                $supplierName = $bill->supplier?->name ?? 'Unknown';

                $buckets[$bucket]['total'] += $remaining;
                $buckets[$bucket]['count']++;

                if (!isset($bySupplier[$supplierId])) {
                    $bySupplier[$supplierId] = [
                        'supplier_id'   => $supplierId,
                        'supplier_name' => $supplierName,
                        'total'         => 0,
                        'current'       => 0,
                        '1_30'          => 0,
                        '31_60'         => 0,
                        '61_90'         => 0,
                        'over_90'       => 0,
                        'count'         => 0,
                    ];
                }

                $bySupplier[$supplierId][$bucket] += $remaining;
                $bySupplier[$supplierId]['total']  += $remaining;
                $bySupplier[$supplierId]['count']++;

                $detailRows[] = [
                    'id'               => $bill->id,
                    'bill_number'      => $bill->bill_number,
                    'bill_date'        => $bill->bill_date ? Carbon::parse($bill->bill_date)->format('d/m/Y') : '-',
                    'due_date'         => $dueDate->format('d/m/Y'),
                    'supplier_id'      => $supplierId,
                    'supplier_name'    => $supplierName,
                    'total_amount'     => (float) $bill->total_amount,
                    'paid_amount'      => (float) $bill->paid_amount,
                    'remaining_amount' => $remaining,
                    'payment_type'     => $bill->payment_type,
                    'days_overdue'     => $isOverdue ? $daysOverdue : 0,
                    'bucket'           => $bucket,
                    'bucket_label'     => $buckets[$bucket]['label'],
                ];
            }

            $totalOutstanding = collect($detailRows)->sum('remaining_amount');
            $totalOverdue     = collect($detailRows)->where('days_overdue', '>', 0)->sum('remaining_amount');
            $avgDays          = count($detailRows) > 0
                ? round(collect($detailRows)->avg('days_overdue'), 1)
                : 0;

            return response()->json([
                'success'    => true,
                'as_of_date' => $asOf->format('d/m/Y'),
                'summary'    => [
                    'total_outstanding' => $totalOutstanding,
                    'total_overdue'     => $totalOverdue,
                    'avg_days_overdue'  => $avgDays,
                    'total_bills'       => count($detailRows),
                ],
                'buckets'    => $buckets,
                'by_supplier' => array_values($bySupplier),
                'details'    => $detailRows,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
