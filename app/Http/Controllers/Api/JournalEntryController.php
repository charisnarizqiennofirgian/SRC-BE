<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Http\Requests\ManualJournalRequest;
use App\Services\JournalService;
use App\Imports\OpeningBalanceImport;
use App\Exports\OpeningBalanceTemplateExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class JournalEntryController extends Controller
{
    protected $journalService;

    public function __construct(JournalService $journalService)
    {
        $this->journalService = $journalService;
    }

    public function index(Request $request)
    {
        $query = JournalEntry::with(['lines.account', 'createdBy:id,name'])
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

        if ($request->start_date) {
            $query->whereDate('date', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('date', '<=', $request->end_date);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->reference_type) {
            $query->where('reference_type', $request->reference_type);
        }

        $journals = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $journals
        ]);
    }

    public function show($id)
    {
        $journal = JournalEntry::with([
            'lines.account',
            'createdBy:id,name'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $journal
        ]);
    }

    public function storeManual(ManualJournalRequest $request)
    {
        DB::beginTransaction();
        try {
            $entries = [];

            foreach ($request->entries as $entry) {
                $entries[] = [
                    'account_id' => $entry['account_id'],
                    'debit' => $entry['debit'] ?? 0,
                    'credit' => $entry['credit'] ?? 0,
                    'description' => $entry['description'] ?? '',
                ];
            }

            $journalEntry = $this->journalService->createJournal(
                $request->date,
                $request->description,
                $entries,
                'MANUAL',
                null
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Jurnal manual berhasil disimpan',
                'data' => $journalEntry->load(['lines.account', 'createdBy'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating manual journal: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan jurnal manual: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ OPENING BALANCE
     */
    public function storeOpeningBalance(Request $request)
    {
        $request->validate([
            'date'        => 'required|date',
            'description' => 'nullable|string',
            'entries'     => 'required|array|min:2',
            'entries.*.account_id' => 'required|exists:chart_of_accounts,id',
            'entries.*.debit'      => 'nullable|numeric|min:0',
            'entries.*.credit'     => 'nullable|numeric|min:0',
            'entries.*.description'=> 'nullable|string',
        ]);

        // Cek apakah sudah ada opening balance
        $existing = JournalEntry::where('is_opening_balance', true)->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Opening Balance sudah pernah dibuat! Hapus yang lama dulu jika ingin mengubah.'
            ], 400);
        }

        // Validasi balance
        $totalDebit  = collect($request->entries)->sum('debit');
        $totalCredit = collect($request->entries)->sum('credit');

        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            return response()->json([
                'success' => false,
                'message' => "Jurnal tidak balance! Total Debit: Rp " . number_format($totalDebit) . " ≠ Total Kredit: Rp " . number_format($totalCredit)
            ], 422);
        }

        DB::beginTransaction();
        try {
            $entries = collect($request->entries)->map(fn($e) => [
                'account_id'  => $e['account_id'],
                'debit'       => $e['debit']  ?? 0,
                'credit'      => $e['credit'] ?? 0,
                'description' => $e['description'] ?? 'Saldo Awal',
            ])->toArray();

            $journalEntry = $this->journalService->createJournal(
                $request->date,
                $request->description ?? 'Opening Balance per ' . $request->date,
                $entries,
                'OPENING_BALANCE',
                null
            );

            // Flag sebagai opening balance
            $journalEntry->update(['is_opening_balance' => true]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Opening Balance berhasil disimpan!',
                'data'    => $journalEntry->load(['lines.account', 'createdBy'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating opening balance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan Opening Balance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ IMPORT OPENING BALANCE DARI EXCEL
     */
    public function importOpeningBalance(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
            'date' => 'required|date',
        ]);

        if (JournalEntry::where('is_opening_balance', true)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Opening Balance sudah ada! Hapus dulu jika ingin upload ulang.'
            ], 400);
        }

        $import = new OpeningBalanceImport();
        Excel::import($import, $request->file('file'));

        // Kalau tidak ada entry sama sekali — kembalikan debug info agar mudah didiagnosa
        if (count($import->entries) === 0 && count($import->errors) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada akun yang terbaca dari file Excel.',
                'debug'   => $import->debug,
                'hint'    => 'Pastikan Excel punya kolom NERACA LAJUR dan SALDO AKHIR, dan ada baris dengan nilai > 0.',
            ], 422);
        }

        if (count($import->errors) > 0) {
            return response()->json([
                'success'        => false,
                'message'        => 'Ada ' . count($import->errors) . ' akun tidak ditemukan di COA sistem.',
                'errors'         => $import->errors,
                'debug'          => $import->debug,
                'akun_berhasil'  => count($import->entries),
                'total_berhasil' => [
                    'debit'  => $import->totalDebit,
                    'kredit' => $import->totalCredit,
                ],
            ], 422);
        }

        $diff = abs($import->totalDebit - $import->totalCredit);
        if ($diff > 1) {
            return response()->json([
                'success' => false,
                'message' => 'Jurnal tidak balance!',
                'data'    => [
                    'total_debit'  => $import->totalDebit,
                    'total_credit' => $import->totalCredit,
                    'selisih'      => $diff,
                    'entries'      => $import->entries,
                ],
                'debug'   => $import->debug,
            ], 422);
        }

        DB::beginTransaction();
        try {
            $journal = $this->journalService->createJournal(
                $request->date,
                'Opening Balance per ' . $request->date,
                $import->entries,
                'OPENING_BALANCE',
                null
            );
            $journal->update(['is_opening_balance' => true]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Opening Balance berhasil diimport! Total ' . count($import->entries) . ' akun.',
                'data'    => [
                    'total_debit'  => $import->totalDebit,
                    'total_credit' => $import->totalCredit,
                    'total_akun'   => count($import->entries),
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error import opening balance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal simpan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ HAPUS OPENING BALANCE
     */
    public function destroyOpeningBalance()
    {
        DB::beginTransaction();
        try {
            $journal = JournalEntry::where('is_opening_balance', true)->first();
            if (!$journal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Opening Balance tidak ditemukan.'
                ], 404);
            }

            $journal->lines()->delete();
            $journal->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Opening Balance berhasil dihapus.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ DOWNLOAD TEMPLATE OPENING BALANCE
     */
    public function downloadTemplate()
    {
        return Excel::download(
            new OpeningBalanceTemplateExport(),
            'template-opening-balance.xlsx'
        );
    }

    /**
     * ✅ CEK STATUS OPENING BALANCE
     */
    public function checkOpeningBalance()
    {
        $journal = JournalEntry::where('is_opening_balance', true)
            ->with(['lines.account'])
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'exists'  => (bool) $journal,
                'journal' => $journal,
            ]
        ]);
    }
    /**
     * ✅ UNPOST JURNAL
     */
    public function unpost(Request $request, $id)
    {
        try {
            $journal = JournalEntry::findOrFail($id);

            $request->validate([
                'reason' => 'required|string|min:5|max:500',
            ]);

            $unpostedJournal = $this->journalService->unpostJournal($journal, $request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Jurnal berhasil di-unpost.',
                'data' => $unpostedJournal->load(['lines.account', 'createdBy', 'unpostedBy'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * ✅ REPOST JURNAL
     */
    public function repost($id)
    {
        try {
            $journal = JournalEntry::findOrFail($id);

            $repostedJournal = $this->journalService->repostJournal($journal);

            return response()->json([
                'success' => true,
                'message' => 'Jurnal berhasil di-post ulang.',
                'data' => $repostedJournal->load(['lines.account', 'createdBy'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * ✅ VOID JURNAL
     */
    public function void(Request $request, $id)
    {
        try {
            $journal = JournalEntry::findOrFail($id);

            $request->validate([
                'reason' => 'required|string|min:5|max:500',
            ]);

            $this->journalService->voidJournal($journal, $request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Jurnal berhasil di-void.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * ✅ UPDATE JURNAL MANUAL
     */
    public function updateManual(Request $request, $id)
    {
        try {
            $journal = JournalEntry::findOrFail($id);

            $request->validate([
                'date' => 'required|date',
                'description' => 'required|string',
                'entries' => 'required|array|min:2',
                'entries.*.account_id' => 'required|exists:chart_of_accounts,id',
                'entries.*.debit' => 'nullable|numeric|min:0',
                'entries.*.credit' => 'nullable|numeric|min:0',
                'entries.*.description' => 'nullable|string',
            ]);

            $updatedJournal = $this->journalService->updateManualJournal(
                $journal,
                $request->date,
                $request->description,
                $request->entries
            );

            return response()->json([
                'success' => true,
                'message' => 'Jurnal manual berhasil di-update.',
                'data' => $updatedJournal
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * ✅ GET HISTORY
     */
    public function history($id)
    {
        try {
            $journal = JournalEntry::findOrFail($id);

            $history = $journal->history()
                ->with('performedBy:id,name')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $history
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
