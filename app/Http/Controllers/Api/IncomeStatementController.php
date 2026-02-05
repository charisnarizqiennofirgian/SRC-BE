<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IncomeStatementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IncomeStatementController extends Controller
{
    protected $incomeStatementService;

    public function __construct(IncomeStatementService $incomeStatementService)
    {
        $this->incomeStatementService = $incomeStatementService;
    }

    public function index(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ], [
            'start_date.required' => 'Tanggal mulai harus diisi',
            'end_date.required' => 'Tanggal akhir harus diisi',
            'end_date.after_or_equal' => 'Tanggal akhir harus sama atau setelah tanggal mulai',
        ]);

        try {
            $data = $this->incomeStatementService->generate(
                startDate: $request->start_date,
                endDate: $request->end_date
            );

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            Log::error('Error generating income statement: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat laporan laba rugi: ' . $e->getMessage()
            ], 500);
        }
    }
}
