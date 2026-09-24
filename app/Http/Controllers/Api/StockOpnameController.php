<?php

namespace App\Http\Controllers\Api;

use App\Exports\StockOpnameSheetExport;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\StockOpname;
use App\Models\StockOpnameDetail;
use App\Services\StockOpnameService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

class StockOpnameController extends Controller
{
    public function __construct(private StockOpnameService $service)
    {
    }

    public function index(Request $request)
    {
        $query = StockOpname::with(['warehouse:id,code,name', 'creator:id,name', 'poster:id,name'])
            ->withCount([
                'details',
                'details as counted_count' => fn ($q) => $q->whereNotNull('real_qty_pcs'),
            ])
            ->orderByDesc('opname_date')
            ->orderByDesc('id');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $query->where('opname_number', 'like', '%' . $request->search . '%');
        }

        return response()->json([
            'success' => true,
            'data'    => $query->paginate((int) $request->input('per_page', 20)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'opname_date'  => ['required', 'date'],
            'notes'        => ['nullable', 'string', 'max:1000'],
        ]);

        $existingDraft = StockOpname::where('warehouse_id', $data['warehouse_id'])
            ->where('status', StockOpname::STATUS_DRAFT)
            ->first();
        if ($existingDraft) {
            return response()->json([
                'success' => false,
                'message' => "Gudang ini masih punya stok opname draft ({$existingDraft->opname_number}). Selesaikan atau hapus dulu.",
            ], 422);
        }

        try {
            $opname = DB::transaction(function () use ($data, $request) {
                $opname = StockOpname::create([
                    'opname_number' => StockOpname::generateNumber($data['opname_date']),
                    'warehouse_id'  => $data['warehouse_id'],
                    'opname_date'   => $data['opname_date'],
                    'notes'         => $data['notes'] ?? null,
                    'status'        => StockOpname::STATUS_DRAFT,
                    'created_by'    => $request->user()?->id,
                ]);
                $this->service->snapshotWarehouse($opname);

                return $opname;
            });

            return response()->json([
                'success' => true,
                'message' => 'Stok opname berhasil dibuat.',
                'data'    => $opname->loadCount('details'),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Gagal membuat stok opname: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'Gagal membuat stok opname.'], 500);
        }
    }

    public function show($id)
    {
        $opname = StockOpname::with(['warehouse:id,code,name', 'creator:id,name', 'poster:id,name'])->findOrFail($id);

        $details = $opname->details()
            ->with(['item:id,code,name,category_id,unit_id', 'item.category:id,name', 'item.unit:id,name'])
            ->orderBy('id')
            ->get();

        $current = $opname->isDraft()
            ? $this->service->currentStockMap($opname->warehouse_id, $details->pluck('item_id')->all())
            : [];

        $rows = $details->map(function (StockOpnameDetail $d) use ($opname, $current) {
            $currentPcs = null;
            $changed = false;
            if ($opname->isDraft()) {
                $inv = $current[$d->item_id . '|' . ($d->grade ?? '')] ?? null;
                $currentPcs = (float) ($inv->qty_pcs ?? 0);
                $changed = abs($currentPcs - $d->system_qty_pcs) > StockOpnameService::EPS;
            }
            $basePcs = $opname->isDraft() ? $currentPcs : $d->posted_system_qty_pcs;

            return [
                'id'                    => $d->id,
                'item_id'               => $d->item_id,
                'item_code'             => $d->item?->code,
                'item_name'             => $d->item?->name,
                'category_name'         => $d->item?->category?->name,
                'unit_name'             => $d->item?->unit?->name,
                'grade'                 => $d->grade,
                'row_type'              => $d->row_type,
                'is_manual'             => $d->is_manual,
                'system_qty_pcs'        => $d->system_qty_pcs,
                'system_qty_natural'    => $d->system_qty_natural,
                'system_qty_warna'      => $d->system_qty_warna,
                'system_qty_m3'         => $d->system_qty_m3,
                'current_qty_pcs'       => $currentPcs,
                'current_changed'       => $changed,
                'real_qty_pcs'          => $d->real_qty_pcs,
                'real_qty_natural'      => $d->real_qty_natural,
                'real_qty_warna'        => $d->real_qty_warna,
                'posted_system_qty_pcs' => $d->posted_system_qty_pcs,
                'diff_qty_pcs'          => $opname->isDraft()
                    ? ($d->real_qty_pcs !== null ? $d->real_qty_pcs - $basePcs : null)
                    : $d->diff_qty_pcs,
                'diff_qty_m3'           => $d->diff_qty_m3,
                'notes'                 => $d->notes,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'header'  => $opname,
                'details' => $rows,
            ],
        ]);
    }

    public function saveDetails(Request $request, $id)
    {
        $opname = StockOpname::findOrFail($id);
        $this->ensureDraft($opname);

        $data = $request->validate([
            'rows'                    => ['required', 'array'],
            'rows.*.id'               => ['required', 'integer'],
            'rows.*.real_qty_pcs'     => ['nullable', 'numeric', 'min:0'],
            'rows.*.real_qty_natural' => ['nullable', 'numeric', 'min:0'],
            'rows.*.real_qty_warna'   => ['nullable', 'numeric', 'min:0'],
            'rows.*.notes'            => ['nullable', 'string', 'max:500'],
        ]);

        $details = $opname->details()->whereIn('id', collect($data['rows'])->pluck('id'))->get()->keyBy('id');

        DB::transaction(function () use ($data, $details) {
            foreach ($data['rows'] as $row) {
                $detail = $details->get($row['id']);
                if (!$detail) {
                    continue;
                }
                $detail->update(array_merge(
                    $this->normalizeReal($detail, $row['real_qty_pcs'] ?? null, $row['real_qty_natural'] ?? null, $row['real_qty_warna'] ?? null),
                    ['notes' => $row['notes'] ?? null]
                ));
            }
        });

        return response()->json(['success' => true, 'message' => 'Hasil hitung berhasil disimpan.']);
    }

    public function searchItems(Request $request)
    {
        $search = trim((string) $request->input('search', ''));
        if (mb_strlen($search) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $items = Item::with('category:id,name')
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'code', 'name', 'category_id'])
            ->map(fn ($item) => [
                'id'            => $item->id,
                'code'          => $item->code,
                'name'          => $item->name,
                'category_name' => $item->category?->name,
                'row_type'      => StockOpnameDetail::rowTypeForCategory($item->category?->name),
            ]);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function addItem(Request $request, $id)
    {
        $opname = StockOpname::findOrFail($id);
        $this->ensureDraft($opname);

        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'grade'   => ['nullable', 'string', 'max:50'],
        ]);
        $grade = isset($data['grade']) && trim($data['grade']) !== '' ? trim($data['grade']) : null;

        $exists = $opname->details()
            ->where('item_id', $data['item_id'])
            ->when($grade === null, fn ($q) => $q->whereNull('grade'), fn ($q) => $q->where('grade', $grade))
            ->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Item ini sudah ada di daftar opname.'], 422);
        }

        $item = Item::with('category:id,name')->findOrFail($data['item_id']);
        $inv = Inventory::where('warehouse_id', $opname->warehouse_id)
            ->where('item_id', $item->id)
            ->where('grade_key', $grade ?? '')
            ->first();

        $detail = $opname->details()->create([
            'item_id'            => $item->id,
            'grade'              => $grade,
            'row_type'           => StockOpnameDetail::rowTypeForCategory($item->category?->name),
            'is_manual'          => true,
            'system_qty_pcs'     => (float) ($inv->qty_pcs ?? 0),
            'system_qty_natural' => (float) ($inv->qty_natural ?? 0),
            'system_qty_warna'   => (float) ($inv->qty_warna ?? 0),
            'system_qty_m3'      => (float) ($inv->qty_m3 ?? 0),
        ]);

        return response()->json(['success' => true, 'message' => 'Item ditambahkan.', 'data' => $detail], 201);
    }

    public function removeItem($id, $detailId)
    {
        $opname = StockOpname::findOrFail($id);
        $this->ensureDraft($opname);

        $detail = $opname->details()->findOrFail($detailId);
        if (!$detail->is_manual) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya item yang ditambahkan manual yang bisa dihapus. Kosongkan kolom REAL kalau tidak dihitung.',
            ], 422);
        }
        $detail->delete();

        return response()->json(['success' => true, 'message' => 'Item dihapus dari opname.']);
    }

    public function export($id)
    {
        $opname = StockOpname::with('warehouse:id,code,name')->findOrFail($id);
        $details = $opname->details()
            ->with(['item:id,code,name,category_id,unit_id', 'item.category:id,name', 'item.unit:id,name'])
            ->orderBy('id')
            ->get();

        $fileName = 'Stok-Opname-' . $opname->opname_number . '-' . Str::slug($opname->warehouse?->name ?? 'gudang') . '.xlsx';

        return Excel::download(new StockOpnameSheetExport($opname, $details), $fileName);
    }

    public function import(Request $request, $id)
    {
        $opname = StockOpname::findOrFail($id);
        $this->ensureDraft($opname);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ], [
            'file.mimes' => 'File harus berformat Excel (.xlsx / .xls) hasil download dari menu ini.',
        ]);

        try {
            $sheet = IOFactory::load($request->file('file')->getRealPath())->getSheet(0);
            $rows = $sheet->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'File Excel tidak bisa dibaca.'], 422);
        }

        $headerIndex = null;
        foreach ($rows as $i => $row) {
            if (trim((string) ($row[0] ?? '')) === 'ID Baris') {
                $headerIndex = $i;
                break;
            }
        }
        if ($headerIndex === null) {
            return response()->json([
                'success' => false,
                'message' => 'Format file tidak sesuai. Pakai file hasil "Download Excel" dari halaman opname ini.',
            ], 422);
        }

        $cols = [];
        foreach ($rows[$headerIndex] as $i => $label) {
            $cols[trim((string) $label)] = $i;
        }

        $details = $opname->details()->get()->keyBy('id');
        $updated = 0;
        $errors = [];

        DB::transaction(function () use ($rows, $headerIndex, $cols, $details, &$updated, &$errors) {
            foreach (array_slice($rows, $headerIndex + 1, null, true) as $i => $row) {
                $excelRow = $i + 1;
                $detailId = (int) ($row[0] ?? 0);
                $detail = $details->get($detailId);
                if (!$detail) {
                    continue;
                }

                $isKomponen = $detail->row_type === StockOpnameDetail::TYPE_KOMPONEN;
                $pcs = $this->parseNumber(isset($cols['REAL']) ? ($row[$cols['REAL']] ?? null) : null);
                $nat = $this->parseNumber(isset($cols['REAL Natural']) ? ($row[$cols['REAL Natural']] ?? null) : null);
                $warna = $this->parseNumber(isset($cols['REAL Warna']) ? ($row[$cols['REAL Warna']] ?? null) : null);
                $notes = isset($cols['Catatan']) ? trim((string) ($row[$cols['Catatan']] ?? '')) : '';

                if ($pcs === false || $nat === false || $warna === false) {
                    $errors[] = "Baris {$excelRow}: angka REAL tidak valid (harus angka ≥ 0).";
                    continue;
                }

                $hasValue = $isKomponen ? ($nat !== null || $warna !== null) : $pcs !== null;
                if (!$hasValue && $notes === '') {
                    continue;
                }

                $payload = $hasValue ? $this->normalizeReal($detail, $pcs, $nat, $warna) : [];
                if ($notes !== '') {
                    $payload['notes'] = mb_substr($notes, 0, 500);
                }
                $detail->update($payload);
                $updated++;
            }
        });

        return response()->json([
            'success' => true,
            'message' => "{$updated} baris berhasil diisi dari Excel." . (count($errors) ? ' ' . count($errors) . ' baris dilewati karena tidak valid.' : ''),
            'data'    => ['updated' => $updated, 'errors' => array_slice($errors, 0, 50)],
        ]);
    }

    public function post(Request $request, $id)
    {
        try {
            $summary = $this->service->post((int) $id, (int) $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => "Stok opname berhasil diposting. {$summary['changed']} baris stok dikoreksi ({$summary['plus']} lebih, {$summary['minus']} kurang).",
                'data'    => $summary,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Exception $e) {
            Log::error('Gagal posting stok opname: ' . $e->getMessage(), ['id' => $id]);

            return response()->json(['success' => false, 'message' => 'Gagal posting stok opname: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $opname = StockOpname::findOrFail($id);
        $this->ensureDraft($opname);
        $opname->delete();

        return response()->json(['success' => true, 'message' => 'Stok opname draft dihapus.']);
    }

    private function ensureDraft(StockOpname $opname): void
    {
        if (!$opname->isDraft()) {
            abort(response()->json([
                'success' => false,
                'message' => 'Stok opname sudah diposting dan tidak bisa diubah.',
            ], 422));
        }
    }

    private function normalizeReal(StockOpnameDetail $detail, $pcs, $nat, $warna): array
    {
        if ($detail->row_type === StockOpnameDetail::TYPE_KOMPONEN) {
            if ($nat === null && $warna === null) {
                return ['real_qty_pcs' => null, 'real_qty_natural' => null, 'real_qty_warna' => null];
            }
            $nat = (float) ($nat ?? 0);
            $warna = (float) ($warna ?? 0);

            return ['real_qty_pcs' => $nat + $warna, 'real_qty_natural' => $nat, 'real_qty_warna' => $warna];
        }

        return [
            'real_qty_pcs'     => $pcs === null ? null : (float) $pcs,
            'real_qty_natural' => null,
            'real_qty_warna'   => null,
        ];
    }

    private function parseNumber($value)
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return $value < 0 ? false : (float) $value;
        }
        $value = trim((string) $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $value = str_replace(' ', '', $value);
        if (str_contains($value, ',') && !str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        }
        if (!is_numeric($value) || (float) $value < 0) {
            return false;
        }

        return (float) $value;
    }
}
