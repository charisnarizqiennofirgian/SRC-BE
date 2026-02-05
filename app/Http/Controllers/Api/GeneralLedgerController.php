<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeneralLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GeneralLedgerController extends Controller
{
    protected $generalLedgerService;

    public function __construct(GeneralLedgerService $generalLedgerService)
    {
        $this->generalLedgerService = $generalLedgerService;
    }

    public function index(Request $request)
    {
        $request->validate([
            'account_id' => 'required|exists:chart_of_accounts,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ], [
            'account_id.required' => 'Akun harus dipilih',
            'account_id.exists' => 'Akun tidak valid',
            'start_date.required' => 'Tanggal mulai harus diisi',
            'end_date.required' => 'Tanggal akhir harus diisi',
            'end_date.after_or_equal' => 'Tanggal akhir harus sama atau setelah tanggal mulai',
        ]);

        try {
            $data = $this->generalLedgerService->getGeneralLedger(
                accountId: $request->account_id,
                startDate: $request->start_date,
                endDate: $request->end_date
            );

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching general ledger: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data buku besar: ' . $e->getMessage()
            ], 500);
        }
    }
}
