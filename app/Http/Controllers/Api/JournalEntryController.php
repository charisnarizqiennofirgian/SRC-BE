<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Http\Requests\ManualJournalRequest;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
