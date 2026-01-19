<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Category;
use App\Models\Stock;
use App\Models\Inventory;
use App\Models\InventoryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\MaterialsImport;

class MaterialController extends Controller
{
    // ✅ Helper: hitung volume_m3 otomatis dari spesifikasi + kategori
    private function calculateVolumeM3(array $itemData): ?float
    {
        $category = Category::find($itemData['category_id'] ?? null);
        if (!$category) return null;

        $name = strtolower($category->name);

        // Kayu RST → volume balok: p x l x t (mm) → m3
        if (str_contains($name, 'kayu rst')) {
            $specs = $itemData['specifications'] ?? null;

            if (is_array($specs) && isset($specs['p'], $specs['l'], $specs['t'])) {
                $p = (float) $specs['p'];
                $l = (float) $specs['l'];
                $t = (float) $specs['t'];

                if ($p > 0 && $l > 0 && $t > 0) {
                    return ($p * $l * $t) / 1000000000; // mm³ → m³
                }
            }
        }

        return null;
    }

    public function index(Request $request)
    {
        try {
            $relations = [];
            if ($request->has('include')) {
                $relations = explode(',', $request->include);

                if ($request->has('category_name') && !in_array('category', $relations)) {
                    $relations[] = 'category';
                }
                $allowedRelations = ['unit', 'category'];
                $relations = array_intersect($relations, $allowedRelations);
            }

            if ($request->query('all')) {
                $items = Item::with(['category:id,name', 'unit:id,name'])
                    ->when($request->filled('category_name'), function ($q) use ($request) {
                        $q->whereHas('category', function ($qq) use ($request) {
                            $qq->where('name', 'like', '%'.$request->category_name.'%');
                        });
                    })
                    ->orderBy('name')
                    ->get();

                // ✅ FIX: Ambil stok dari tabel inventories (bukan items.stock)
                $packingWarehouseId = 11; // Gudang Packing (Barang Jadi)

                $items->transform(function ($item) use ($packingWarehouseId, $request) {
                    // Jika kategori Produk Jadi, ambil stok dari Gudang Packing
                    if ($request->filled('category_name') && str_contains(strtolower($request->category_name), 'produk jadi')) {
                        $inventory = Inventory::where('item_id', $item->id)
                            ->where('warehouse_id', $packingWarehouseId)
                            ->first();
                        $item->stock = $inventory ? (float) $inventory->qty : 0;
                    }

                    return $item;
                });

                return response()->json(['success' => true, 'data' => $items]);
            }

            $perPage = min($request->input('per_page', 50), 100);
            $search  = $request->input('search');

            $query = Item::with($relations)
                ->select(
                    'id',
                    'name',
                    'code',
                    'unit_id',
                    'category_id',
                    'stock',
                    'description',
                    'created_at',
                    'specifications',
                    'nw_per_box',
                    'gw_per_box',
                    'wood_consumed_per_pcs',
                    'm3_per_carton',
                    'hs_code',
                    'jenis',
                    'kualitas',
                    'bentuk',
                    'volume_m3',
                );

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            }

            if ($request->has('category_id') && $request->category_id) {
                $query->where('category_id', $request->category_id);
            } elseif ($request->has('category_name') && $request->category_name) {
                $query->whereHas('category', function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->category_name . '%');
                });
            }

            $sortBy    = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');

            $allowedSortFields = ['name', 'code', 'stock', 'created_at'];
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->latest();
            }

            $items = $query->paginate($perPage);

            return response()->json(['success' => true, 'data' => $items]);
        } catch (\Exception $e) {
            Log::error('Error saat mengambil data barang: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data barang.',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name' => 'required|string|max:255',
                'code' => 'nullable|string|max:255|unique:items,code',
                'category_id' => 'required|exists:categories,id',
                'unit_id' => 'required|exists:units,id',
                'description' => 'nullable|string',
                'stock' => 'nullable|numeric|min:0',
                'initial_warehouse_id' => 'nullable|exists:warehouses,id',
                'specifications' => 'nullable|array',
                'specifications.t' => 'nullable|numeric|min:0',
                'specifications.l' => 'nullable|numeric|min:0',
                'specifications.p' => 'nullable|numeric|min:0',
                'nw_per_box' => 'nullable|numeric|min:0',
                'gw_per_box' => 'nullable|numeric|min:0',
                'wood_consumed_per_pcs' => 'nullable|numeric|min:0',
                'm3_per_carton' => 'nullable|numeric|min:0',
                'hs_code' => 'nullable|string|max:50',
                'jenis' => 'nullable|string|max:255',
                'kualitas' => 'nullable|string|max:255',
                'bentuk' => 'nullable|string|max:255',
                'volume_m3' => 'nullable|numeric|min:0',
            ],
            [
                'name.required' => 'Nama barang wajib diisi.',
                'code.unique' => 'Kode barang sudah digunakan.',
                'category_id.required' => 'Kategori wajib dipilih.',
                'unit_id.required' => 'Satuan wajib dipilih.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $itemData = $validator->validated();

            if (isset($itemData['specifications'])) {
                if (
                    empty($itemData['specifications']['t']) &&
                    empty($itemData['specifications']['l']) &&
                    empty($itemData['specifications']['p'])
                ) {
                    $itemData['specifications'] = null;
                }
            }

            $itemData['jenis'] = $itemData['jenis'] ?? null;
            $itemData['kualitas'] = $itemData['kualitas'] ?? null;
            $itemData['bentuk'] = $itemData['bentuk'] ?? null;

            // FORMAT NAMA KHUSUS KAYU RST
            $category = Category::find($itemData['category_id'] ?? null);
            if (
                $category &&
                $category->name === 'Kayu RST' &&
                !empty($itemData['specifications'])
            ) {
                $namaDasar = $itemData['name'];
                $t = $itemData['specifications']['t'] ?? null;
                $l = $itemData['specifications']['l'] ?? null;
                $p = $itemData['specifications']['p'] ?? null;

                if ($t && $l && $p) {
                    $itemData['name'] = "{$namaDasar} ({$t}x{$l}x{$p})";
                }
            }

            // HITUNG volume_m3 OTOMATIS (Kayu RST)
            $volume = $this->calculateVolumeM3($itemData);
            if (!is_null($volume)) {
                $itemData['volume_m3'] = $volume;
            }

            DB::beginTransaction();

            $item = Item::create($itemData);
            $item->load(['unit:id,name', 'category:id,name']);

            // FASE 2: alokasikan stok awal ke tabel stocks + inventories + inventory_logs
            $initialStock = (float) ($itemData['stock'] ?? 0);
            $initialWarehouseId = $itemData['initial_warehouse_id'] ?? null;

            if ($initialStock > 0 && $initialWarehouseId) {
                // Update tabel stocks (legacy)
                Stock::updateOrCreate(
                    [
                        'item_id' => $item->id,
                        'warehouse_id' => $initialWarehouseId,
                    ],
                    [
                        'quantity' => $initialStock,
                    ]
                );

                // ✅ Update tabel inventories
                Inventory::updateOrCreate(
                    [
                        'item_id' => $item->id,
                        'warehouse_id' => $initialWarehouseId,
                    ],
                    [
                        'qty' => $initialStock,
                    ]
                );

                // ✅ Catat ke inventory_logs sebagai INITIAL_STOCK
                InventoryLog::create([
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'item_id' => $item->id,
                    'warehouse_id' => $initialWarehouseId,
                    'qty' => $initialStock,
                    'direction' => 'IN',
                    'transaction_type' => 'INITIAL_STOCK',
                    'reference_type' => 'InitialStock',
                    'reference_id' => $item->id,
                    'reference_number' => 'INIT-' . $item->code,
                    'notes' => 'Stok awal saat pembuatan item',
                    'user_id' => Auth::id(),
                ]);
            }

            DB::commit();

            Cache::forget('materials_all');

            return response()->json([
                'success' => true,
                'message' => 'Barang berhasil ditambahkan.',
                'data' => $item,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saat membuat item: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan data.',
            ], 500);
        }
    }

    public function show(Item $material)
    {
        try {
            $material->load(['unit:id,name', 'category:id,name']);
            return response()->json([
                'success' => true,
                'data' => $material,
            ]);
        } catch (\Exception $e) {
            Log::error('Error saat mengambil detail item: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.',
            ], 404);
        }
    }

    public function update(Request $request, Item $material)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name' => 'required|string|max:255',
                'code' => 'nullable|string|max:255|unique:items,code,' . $material->id,
                'category_id' => 'required|exists:categories,id',
                'unit_id' => 'required|exists:units,id',
                'description' => 'nullable|string',
                'stock' => 'nullable|numeric|min:0',
                'initial_warehouse_id' => 'nullable|exists:warehouses,id',
                'specifications' => 'nullable|array',
                'specifications.t' => 'nullable|numeric|min:0',
                'specifications.l' => 'nullable|numeric|min:0',
                'specifications.p' => 'nullable|numeric|min:0',
                'nw_per_box' => 'nullable|numeric|min:0',
                'gw_per_box' => 'nullable|numeric|min:0',
                'wood_consumed_per_pcs' => 'nullable|numeric|min:0',
                'm3_per_carton' => 'nullable|numeric|min:0',
                'hs_code' => 'nullable|string|max:50',
                'jenis' => 'nullable|string|max:255',
                'kualitas' => 'nullable|string|max:255',
                'bentuk' => 'nullable|string|max:255',
                'volume_m3' => 'nullable|numeric|min:0',
            ],
            [
                'name.required' => 'Nama barang wajib diisi.',
                'code.unique' => 'Kode barang sudah digunakan.',
                'category_id.required' => 'Kategori wajib dipilih.',
                'unit_id.required' => 'Satuan wajib dipilih.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $itemData = $validator->validated();

            if (isset($itemData['specifications'])) {
                if (
                    empty($itemData['specifications']['t']) &&
                    empty($itemData['specifications']['l']) &&
                    empty($itemData['specifications']['p'])
                ) {
                    $itemData['specifications'] = null;
                }
            }

            $itemData['jenis'] = $itemData['jenis'] ?? null;
            $itemData['kualitas'] = $itemData['kualitas'] ?? null;
            $itemData['bentuk'] = $itemData['bentuk'] ?? null;

            // FORMAT NAMA KHUSUS KAYU RST SAAT UPDATE
            $category = Category::find($itemData['category_id'] ?? null);
            if (
                $category &&
                $category->name === 'Kayu RST' &&
                !empty($itemData['specifications'])
            ) {
                $namaDasar = $itemData['name'];
                $t = $itemData['specifications']['t'] ?? null;
                $l = $itemData['specifications']['l'] ?? null;
                $p = $itemData['specifications']['p'] ?? null;

                if ($t && $l && $p) {
                    $itemData['name'] = "{$namaDasar} ({$t}x{$l}x{$p})";
                }
            }

            $volume = $this->calculateVolumeM3($itemData);
            if (!is_null($volume)) {
                $itemData['volume_m3'] = $volume;
            }

            DB::beginTransaction();

            $material->update($itemData);
            $material->load(['unit:id,name', 'category:id,name']);

            // Update stok jika ada perubahan
            if (array_key_exists('stock', $itemData) && isset($itemData['initial_warehouse_id'])) {
                $initialStock = (float) ($itemData['stock'] ?? 0);
                $initialWarehouseId = $itemData['initial_warehouse_id'];

                if ($initialStock > 0 && $initialWarehouseId) {
                    // Cek stok lama
                    $oldInventory = Inventory::where('item_id', $material->id)
                        ->where('warehouse_id', $initialWarehouseId)
                        ->first();
                    $oldQty = $oldInventory ? (float) $oldInventory->qty : 0;

                    // Update stocks (legacy)
                    Stock::updateOrCreate(
                        [
                            'item_id' => $material->id,
                            'warehouse_id' => $initialWarehouseId,
                        ],
                        [
                            'quantity' => $initialStock,
                        ]
                    );

                    // ✅ Update inventories
                    Inventory::updateOrCreate(
                        [
                            'item_id' => $material->id,
                            'warehouse_id' => $initialWarehouseId,
                        ],
                        [
                            'qty' => $initialStock,
                        ]
                    );

                    // ✅ Catat penyesuaian ke inventory_logs jika ada perubahan
                    $diff = $initialStock - $oldQty;
                    if ($diff != 0) {
                        InventoryLog::create([
                            'date' => now()->toDateString(),
                            'time' => now()->toTimeString(),
                            'item_id' => $material->id,
                            'warehouse_id' => $initialWarehouseId,
                            'qty' => abs($diff),
                            'direction' => $diff > 0 ? 'IN' : 'OUT',
                            'transaction_type' => 'ADJUSTMENT',
                            'reference_type' => 'Adjustment',
                            'reference_id' => $material->id,
                            'reference_number' => 'ADJ-' . $material->code,
                            'notes' => 'Penyesuaian stok via edit item',
                            'user_id' => Auth::id(),
                        ]);
                    }
                }
            }

            DB::commit();

            Cache::forget('materials_all');

            return response()->json([
                'success' => true,
                'message' => 'Barang berhasil diperbarui.',
                'data' => $material,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saat update item: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui data.',
            ], 500);
        }
    }

    public function destroy(Item $material)
    {
        try {
            $material->delete();
            Cache::forget('materials_all');

            return response()->json([
                'success' => true,
                'message' => 'Barang berhasil dihapus.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error saat menghapus item: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus barang. Kemungkinan data ini terhubung dengan data lain.',
            ], 500);
        }
    }

    public function downloadTemplate(Request $request)
    {
        try {
            $headers = [
                'kode', 'nama', 'kategori', 'satuan', 'stok_awal', 'gudang_awal', 'deskripsi',
                'spec_p', 'spec_l', 'spec_t',
                'nw_per_box', 'gw_per_box', 'wood_consumed_per_pcs', 'm3_per_carton', 'hs_code',
            ];

            $example1 = [
                'PJ-001', 'KILT DINING', 'Produk Jadi', 'Pcs', 10, 'PACKING', 'Produk KILT',
                '', '', '',
                '27.0', '42.0', '0.0099', '0.045', '4403.99',
            ];

            $example2 = [
                'BOX-001', 'BOX A KILT', 'Karton Box', 'Pcs', 100, 'PACKING', 'Box untuk KILT',
                '960', '940', '940',
                '', '', '', '', '',
            ];

            $content = implode(';', $headers) . "\n";
            $content .= implode(';', $example1) . "\n";
            $content .= implode(';', $example2) . "\n";

            $fileName = 'template_master_barang_all_' . date('Ymd') . '.csv';

            return response($content)
                ->header('Content-Type', 'text/csv; charset=UTF-8')
                ->header('Content-Disposition', "attachment; filename=\"{$fileName}\"")
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
        } catch (\Exception $e) {
            Log::error('Error download template: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mendownload template.',
            ], 500);
        }
    }

    public function import(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'file' => [
                    'required',
                    'file',
                    'max:5120',
                    function ($attribute, $value, $fail) {
                        $extension = strtolower($value->getClientOriginalExtension());
                        $allowedExtensions = ['xlsx', 'xls', 'csv'];

                        if (!in_array($extension, $allowedExtensions)) {
                            $fail('Format file harus xlsx, xls, atau csv.');
                        }
                    },
                ],
            ],
            [
                'file.required' => 'File wajib diupload.',
                'file.file' => 'File tidak valid.',
                'file.max' => 'Ukuran file maksimal 5MB.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            Excel::import(new MaterialsImport(), $request->file('file'));
            Cache::forget('materials_all');

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil di-import.',
            ], 200);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();
            $errors = [];
            foreach ($failures as $failure) {
                $errors[] = [
                    'row' => $failure->row(),
                    'attribute' => $failure->attribute(),
                    'errors' => $failure->errors(),
                    'values' => $failure->values(),
                ];
            }

            return response()->json([
                'success' => false,
                'message' => 'Validasi data gagal.',
                'errors' => $errors,
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error import material: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat import data: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request)
    {
        try {
            $search = $request->input('search');
            $query = Item::with(['unit:id,name', 'category:id,name']);
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            }
            $items = $query->get();
            return response()->json([
                'success' => false,
                'message' => 'Fitur export belum diimplementasikan.',
            ], 501);
        } catch (\Exception $e) {
            Log::error('Error export material: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat export data.',
            ], 500);
        }
    }
}
