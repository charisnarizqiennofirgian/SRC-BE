<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SawmillProduction;
use Illuminate\Http\Request;

class SawmillReportController extends Controller
{
    public function index(Request $request)
    {
        $query = SawmillProduction::query()
            ->orderBy('date', 'desc');

        if ($request->filled('start_date')) {
            $query->whereDate('date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('date', '<=', $request->end_date);
        }

        $productions = $query->get();

        // data utk tabel (tetap sama struktur yg dipakai Vue)
        $data = $productions->map(function ($prod) {
            return [
                'id' => $prod->id,
                'date' => $prod->date?->format('Y-m-d'),
                'document_number' => $prod->document_number,
                'total_log_m3' => $prod->total_log_m3,
                'total_rst_m3' => $prod->total_rst_m3,
                'yield_percent' => $prod->yield_percent,
            ];
        });

        // summary agregat (bisa dipakai nanti di UI)
        $totalLog = $productions->sum('total_log_m3');
        $totalRst = $productions->sum('total_rst_m3');
        $avgYield = $productions->count() > 0
            ? round($productions->avg('yield_percent'), 2)
            : 0;

        return response()->json([
            'success' => true,
            'data' => $data,
            'summary' => [
                'total_log_m3' => $totalLog,
                'total_rst_m3' => $totalRst,
                'avg_yield_percent' => $avgYield,
            ],
        ]);
    }
}
