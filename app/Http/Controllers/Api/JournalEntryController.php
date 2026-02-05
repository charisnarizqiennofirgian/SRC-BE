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
}
