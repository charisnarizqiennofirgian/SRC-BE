<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ArAgingController extends Controller
{
    public function index(Request $request)
    {
        try {
            $asOf    = $request->filled('as_of_date')
                ? Carbon::parse($request->as_of_date)->endOfDay()
                : Carbon::today();

            $query = SalesInvoice::with('buyer')
                ->where('status', 'POSTED')
                ->where('remaining_amount', '>', 0)
                ->where('invoice_date', '<=', $asOf);

            if ($request->filled('buyer_id')) {
                $query->where('buyer_id', $request->buyer_id);
            }

            $invoices = $query->orderBy('due_date')->get();

            $buckets = [
                'current' => ['label' => 'Belum Jatuh Tempo', 'total' => 0, 'count' => 0],
                '1_30'    => ['label' => '1 - 30 Hari',       'total' => 0, 'count' => 0],
                '31_60'   => ['label' => '31 - 60 Hari',      'total' => 0, 'count' => 0],
                '61_90'   => ['label' => '61 - 90 Hari',      'total' => 0, 'count' => 0],
                'over_90' => ['label' => '> 90 Hari',         'total' => 0, 'count' => 0],
            ];

            $byBuyer    = [];
            $detailRows = [];

            foreach ($invoices as $inv) {
                $dueDate     = Carbon::parse($inv->due_date);
                $daysOverdue = $dueDate->isPast() ? (int) $dueDate->diffInDays($asOf) : 0;
                $isOverdue   = $asOf->gt($dueDate);

                $bucket = match (true) {
                    !$isOverdue       => 'current',
                    $daysOverdue <= 30 => '1_30',
                    $daysOverdue <= 60 => '31_60',
                    $daysOverdue <= 90 => '61_90',
                    default            => 'over_90',
                };

                $remaining = (float) $inv->remaining_amount;

                $buckets[$bucket]['total'] += $remaining;
                $buckets[$bucket]['count']++;

                $buyerId   = $inv->buyer_id;
                $buyerName = $inv->buyer?->name ?? 'Unknown';

                if (!isset($byBuyer[$buyerId])) {
                    $byBuyer[$buyerId] = [
                        'buyer_id'   => $buyerId,
                        'buyer_name' => $buyerName,
                        'total'      => 0,
                        'current'    => 0,
                        '1_30'       => 0,
                        '31_60'      => 0,
                        '61_90'      => 0,
                        'over_90'    => 0,
                        'count'      => 0,
                    ];
                }

                $byBuyer[$buyerId][$bucket] += $remaining;
                $byBuyer[$buyerId]['total']  += $remaining;
                $byBuyer[$buyerId]['count']++;

                $detailRows[] = [
                    'id'               => $inv->id,
                    'invoice_number'   => $inv->invoice_number,
                    'invoice_date'     => $inv->invoice_date ? Carbon::parse($inv->invoice_date)->format('d/m/Y') : '-',
                    'due_date'         => $dueDate->format('d/m/Y'),
                    'buyer_id'         => $buyerId,
                    'buyer_name'       => $buyerName,
                    'currency'         => $inv->currency,
                    'total_idr'        => (float) $inv->total_idr,
                    'paid_amount'      => (float) $inv->paid_amount,
                    'remaining_amount' => $remaining,
                    'payment_status'   => $inv->payment_status,
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
                'success'           => true,
                'as_of_date'        => $asOf->format('d/m/Y'),
                'summary'           => [
                    'total_outstanding' => $totalOutstanding,
                    'total_overdue'     => $totalOverdue,
                    'avg_days_overdue'  => $avgDays,
                    'total_invoices'    => count($detailRows),
                ],
                'buckets'           => $buckets,
                'by_buyer'          => array_values($byBuyer),
                'details'           => $detailRows,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
