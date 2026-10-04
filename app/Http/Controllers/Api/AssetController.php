<?php

namespace App\Http\Controllers\Api;

use App\Exports\AssetExport;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetServiceRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class AssetController extends Controller
{
    public function options()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'categories' => Asset::getCategories(),
                'conditions' => Asset::getConditions(),
                'statuses' => Asset::getStatuses(),
                'service_types' => AssetServiceRecord::getTypes(),
                'locations' => Asset::whereNotNull('location')->where('location', '!=', '')
                    ->distinct()->orderBy('location')->pluck('location'),
                'due_soon_days' => Asset::DUE_SOON_DAYS,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = $this->filteredQuery($request)
            ->with('latestServiceRecord')
            ->withSum('serviceRecords as total_service_cost', 'cost')
            ->orderBy('category')
            ->orderBy('code');

        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(fn (Asset $asset) => $this->transform($asset));

        $all = $this->filteredQuery($request)->with('latestServiceRecord')->get();

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => [
                'total_units' => $all->count(),
                'in_repair' => $all->where('status', Asset::STATUS_PERBAIKAN)->count(),
                'damaged' => $all->whereIn('condition', [Asset::CONDITION_RUSAK_RINGAN, Asset::CONDITION_RUSAK_BERAT])->count(),
                'due_alerts' => $all->filter(fn (Asset $a) => count($a->getDueAlerts()) > 0)->count(),
                'total_purchase_value' => round($all->sum(fn (Asset $a) => (float) $a->purchase_price), 2),
            ],
        ]);
    }

    public function show($id)
    {
        $asset = Asset::with(['serviceRecords.creator:id,name', 'latestServiceRecord', 'creator:id,name'])
            ->withSum('serviceRecords as total_service_cost', 'cost')
            ->findOrFail($id);

        $data = $this->transform($asset);
        $data['service_records'] = $asset->serviceRecords;
        $data['creator'] = $asset->creator;

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(Request $request)
    {
        $data = $this->validateAsset($request);

        $asset = DB::transaction(function () use ($data, $request) {
            if (empty($data['code'])) {
                $data['code'] = Asset::generateCode($data['category']);
            }
            $data['created_by'] = $request->user()?->id;

            return Asset::create($data);
        });

        return response()->json([
            'success' => true,
            'message' => "Inventaris {$asset->code} berhasil ditambahkan.",
            'data' => $asset,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $asset = Asset::findOrFail($id);
        $data = $this->validateAsset($request, $asset);

        if (empty($data['code'])) {
            $data['code'] = $asset->code;
        }

        $asset->update($data);

        return response()->json([
            'success' => true,
            'message' => "Inventaris {$asset->code} berhasil diperbarui.",
            'data' => $asset,
        ]);
    }

    public function destroy($id)
    {
        $asset = Asset::findOrFail($id);
        $asset->delete();

        return response()->json(['success' => true, 'message' => "Inventaris {$asset->code} berhasil dihapus."]);
    }

    public function storeService(Request $request, $id)
    {
        $asset = Asset::findOrFail($id);
        $data = $this->validateService($request);
        $data['asset_id'] = $asset->id;
        $data['created_by'] = $request->user()?->id;

        $record = AssetServiceRecord::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Riwayat servis berhasil ditambahkan.',
            'data' => $record,
        ], 201);
    }

    public function updateService(Request $request, $id, $recordId)
    {
        $record = AssetServiceRecord::where('asset_id', $id)->findOrFail($recordId);
        $record->update($this->validateService($request));

        return response()->json([
            'success' => true,
            'message' => 'Riwayat servis berhasil diperbarui.',
            'data' => $record,
        ]);
    }

    public function destroyService($id, $recordId)
    {
        $record = AssetServiceRecord::where('asset_id', $id)->findOrFail($recordId);
        $record->delete();

        return response()->json(['success' => true, 'message' => 'Riwayat servis berhasil dihapus.']);
    }

    public function export(Request $request)
    {
        $assets = $this->filteredQuery($request)
            ->with('latestServiceRecord')
            ->withSum('serviceRecords as total_service_cost', 'cost')
            ->orderBy('category')
            ->orderBy('code')
            ->get();

        return Excel::download(new AssetExport($assets), 'Inventaris-' . now()->format('Ymd') . '.xlsx');
    }

    private function filteredQuery(Request $request)
    {
        $query = Asset::query();

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                foreach (['code', 'name', 'brand', 'model_type', 'serial_number', 'plate_number', 'pic'] as $col) {
                    $q->orWhere($col, 'like', "%{$search}%");
                }
            });
        }

        foreach (['category', 'condition', 'status', 'location'] as $field) {
            if ($value = $request->input($field)) {
                $query->where($field, $value);
            }
        }

        return $query;
    }

    private function transform(Asset $asset): array
    {
        $data = $asset->toArray();
        unset($data['latest_service_record']);
        $data['category_label'] = Asset::getCategories()[$asset->category] ?? $asset->category;
        $data['condition_label'] = Asset::getConditions()[$asset->condition] ?? $asset->condition;
        $data['status_label'] = Asset::getStatuses()[$asset->status] ?? $asset->status;
        $data['book_value'] = $asset->getBookValue();
        $data['total_service_cost'] = round((float) ($asset->total_service_cost ?? 0), 2);
        $data['last_service_date'] = $asset->latestServiceRecord?->service_date?->toDateString();
        $data['next_service_date'] = $asset->latestServiceRecord?->next_service_date?->toDateString();
        $data['due_alerts'] = $asset->getDueAlerts();

        return $data;
    }

    private function validateAsset(Request $request, ?Asset $asset = null): array
    {
        $validator = Validator::make($request->all(), [
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('assets', 'code')->ignore($asset?->id),
            ],
            'name' => 'required|string|max:255',
            'category' => ['required', Rule::in(array_keys(Asset::getCategories()))],
            'brand' => 'nullable|string|max:255',
            'model_type' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'pic' => 'nullable|string|max:255',
            'purchase_date' => 'nullable|date',
            'purchase_price' => 'nullable|numeric|min:0',
            'useful_life_years' => 'nullable|integer|min:1|max:100',
            'supplier_name' => 'nullable|string|max:255',
            'condition' => ['required', Rule::in(array_keys(Asset::getConditions()))],
            'status' => ['required', Rule::in(array_keys(Asset::getStatuses()))],
            'plate_number' => 'nullable|string|max:30',
            'chassis_number' => 'nullable|string|max:255',
            'engine_number' => 'nullable|string|max:255',
            'tax_due_date' => 'nullable|date',
            'kir_due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ], [
            'code.unique' => 'Kode inventaris sudah dipakai.',
            'name.required' => 'Nama inventaris wajib diisi.',
            'category.required' => 'Kategori wajib dipilih.',
        ]);

        $data = $validator->validate();
        $data['code'] = isset($data['code']) ? strtoupper(trim($data['code'])) : null;
        $data['purchase_price'] = $data['purchase_price'] ?? 0;

        if (!(new Asset(['category' => $data['category']]))->isVehicle()) {
            foreach (['plate_number', 'chassis_number', 'engine_number', 'tax_due_date', 'kir_due_date'] as $field) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    private function validateService(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'service_date' => 'required|date',
            'service_type' => ['required', Rule::in(array_keys(AssetServiceRecord::getTypes()))],
            'description' => 'required|string',
            'vendor' => 'nullable|string|max:255',
            'cost' => 'nullable|numeric|min:0',
            'meter_reading' => 'nullable|string|max:50',
            'next_service_date' => 'nullable|date|after_or_equal:service_date',
            'notes' => 'nullable|string',
        ], [
            'service_date.required' => 'Tanggal servis wajib diisi.',
            'description.required' => 'Keterangan servis wajib diisi.',
            'next_service_date.after_or_equal' => 'Jadwal servis berikutnya tidak boleh sebelum tanggal servis.',
        ])->validate();
        $data['cost'] = $data['cost'] ?? 0;

        return $data;
    }
}
