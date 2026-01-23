<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    /**
     * Daftar semua jurnal dengan pagination & filter
     */
    public function index(Request $request)
    {
        $query = JournalEntry::with(['lines.account', 'createdBy:id,name'])
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

        // Filter by date range
        if ($request->start_date) {
            $query->whereDate('date', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('date', '<=', $request->end_date);
        }

        // Filter by status
        if ($request->status) {
            $query->where('status', $request->status);
        }

        // Filter by reference type
        if ($request->reference_type) {
            $query->where('reference_type', $request->reference_type);
        }

        $journals = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $journals
        ]);
    }

    /**
     * Detail jurnal dengan lines
     */
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
}
