<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemDimensionHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ItemDimensionHistoryController extends Controller
{
    // GET /items/{id}/dimension-history
    public function index($itemId)
    {
        $item = Item::findOrFail($itemId);

        $histories = ItemDimensionHistory::where('item_id', $itemId)
            ->with('changedBy:id,name')
            ->latest()
            ->get()
            ->map(fn($h) => [
                'id'         => $h->id,
                'changed_at' => $h->created_at->format('d/m/Y H:i'),
                'changed_by' => $h->changedBy?->name ?? 'System',
                'old_p'      => $h->old_p,
                'old_l'      => $h->old_l,
                'old_t'      => $h->old_t,
                'new_p'      => $h->new_p,
                'new_l'      => $h->new_l,
                'new_t'      => $h->new_t,
                'notes'      => $h->notes,
            ]);

        return response()->json([
            'success' => true,
            'data'    => $histories,
        ]);
    }

    // PUT /items/{id}/dimensions
    public function update(Request $request, $itemId)
    {
        $request->validate([
            'p'     => 'required|numeric|min:0',
            'l'     => 'required|numeric|min:0',
            't'     => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:255',
        ]);

        $item = Item::findOrFail($itemId);

        // Ambil dimensi lama dari specifications
        $spec  = $item->specifications ?? [];
        $old_p = (float) ($spec['p'] ?? $item->length_mm  ?? 0);
        $old_l = (float) ($spec['l'] ?? $item->width_mm   ?? 0);
        $old_t = (float) ($spec['t'] ?? $item->thickness_mm ?? 0);

        $new_p = (float) $request->p;
        $new_l = (float) $request->l;
        $new_t = (float) $request->t;

        // Kalau tidak ada perubahan, skip
        if ($old_p == $new_p && $old_l == $new_l && $old_t == $new_t) {
            return response()->json([
                'success' => false,
                'message' => 'Dimensi tidak berubah.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Catat history
            ItemDimensionHistory::create([
                'item_id'    => $item->id,
                'changed_by' => Auth::id(),
                'old_p'      => $old_p,
                'old_l'      => $old_l,
                'old_t'      => $old_t,
                'new_p'      => $new_p,
                'new_l'      => $new_l,
                'new_t'      => $new_t,
                'notes'      => $request->notes,
            ]);

            // Update specifications
            $spec['p'] = $new_p;
            $spec['l'] = $new_l;
            $spec['t'] = $new_t;

            $item->update([
                'specifications' => $spec,
                'length_mm'      => $new_p,
                'width_mm'       => $new_l,
                'thickness_mm'   => $new_t,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dimensi berhasil diperbarui.',
                'data'    => [
                    'item_id' => $item->id,
                    'new_p'   => $new_p,
                    'new_l'   => $new_l,
                    'new_t'   => $new_t,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal update dimensi: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui dimensi.',
            ], 500);
        }
    }
}