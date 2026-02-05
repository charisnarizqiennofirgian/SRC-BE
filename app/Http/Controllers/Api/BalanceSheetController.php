<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BalanceSheetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BalanceSheetController extends Controller
{
    protected $balanceSheetService;

    public function __construct(BalanceSheetService $balanceSheetService)
    {
        $this->balanceSheetService = $balanceSheetService;
    }

    public function index(Request $request)
    {
        $request->validate([
            'as_of_date' => 'required|date',
        ], [
            'as_of_date.required' => 'Tanggal harus diisi',
            'as_of_date.date' => 'Format tanggal tidak valid',
        ]);

        try {
            $data = $this->balanceSheetService->generate(
                asOfDate: $request->as_of_date
            );

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            Log::error('Error generating balance sheet: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat neraca: ' . $e->getMessage()
            ], 500);
        }
    }
}
