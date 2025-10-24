<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\MaterialsImport;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        try {
            $relations = [];
            if ($request->has('include')) {
                $relations = explode(',', $request->include);
                $allowedRelations = ['unit', 'category'];
                $relations = array_intersect($relations, $allowedRelations);
            }

            if ($request->query('all')) {
                $items = Item::with($relations)->orderBy('name')->get();
                return response()->json(['success' => true, 'data' => $items]);
            }

            $perPage = min($request->input('per_page', 50), 100);
            $search = $request->input('search');
            
            $query = Item::with($relations)
                ->select('id', 'name', 'code', 'unit_id', 'category_id', 'stock', 'description', 'created_at');
            
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            }
            
            if ($request->has('category_id') && $request->category_id) {
                $query->where('category_id', $request->category_id);
            }
            
            $sortBy = $request->input('sort_by', 'created_at');
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
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:items,code',
            'category_id' => 'required|exists:categories,id', 
            'unit_id' => 'required|exists:units,id',
            'description' => 'nullable|string',
            'stock' => 'nullable|numeric|min:0',
        ], [
            'name.required' => 'Nama barang wajib diisi.',
            'code.unique' => 'Kode barang sudah digunakan.',
            'category_id.required' => 'Kategori wajib dipilih.',
            'category_id.exists' => 'Kategori tidak valid.',
            'unit_id.required' => 'Satuan wajib dipilih.',
            'unit_id.exists' => 'Satuan tidak valid.',
            'stock.numeric' => 'Stok harus berupa angka.',
            'stock.min' => 'Stok tidak boleh negatif.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $itemData = $validator->validated();
            $item = Item::create($itemData);
            
            $item->load(['unit:id,name', 'category:id,name']);
            Cache::forget('materials_all');
            
            return response()->json([
                'success' => true,
                'message' => 'Barang berhasil ditambahkan.',
                'data' => $item
            ], 201);
            
        } catch (\Exception $e) {
            Log::error('Error saat membuat item: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan data.'
            ], 500);
        }
    }
    
    public function show(Item $material)
    {
        try {
            $material->load(['unit:id,name', 'category:id,name']);
            
            return response()->json([
                'success' => true,
                'data' => $material
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error saat mengambil detail item: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.'
            ], 404);
        }
    }
    
    public function update(Request $request, Item $material)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:items,code,' . $material->id,
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'description' => 'nullable|string',
            'stock' => 'nullable|numeric|min:0',
        ], [
            'name.required' => 'Nama barang wajib diisi.',
            'code.unique' => 'Kode barang sudah digunakan.',
            'category_id.required' => 'Kategori wajib dipilih.',
            'category_id.exists' => 'Kategori tidak valid.',
            'unit_id.required' => 'Satuan wajib dipilih.',
            'unit_id.exists' => 'Satuan tidak valid.',
            'stock.numeric' => 'Stok harus berupa angka.',
            'stock.min' => 'Stok tidak boleh negatif.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $material->update($validator->validated());
            $material->load(['unit:id,name', 'category:id,name']);
            Cache::forget('materials_all');
            
            return response()->json([
                'success' => true,
                'message' => 'Barang berhasil diperbarui.',
                'data' => $material
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error saat update item: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui data.'
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
                'message' => 'Barang berhasil dihapus.'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error saat menghapus item: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus barang. Kemungkinan data ini terhubung dengan data lain.'
            ], 500);
        }
    }
    
    public function downloadTemplate()
    {
        try {
            $headers = 'kode;nama;kategori;satuan;stok_awal;deskripsi';
            $example = 'BB-001;Kayu Jati;Bahan Baku;Lembar;50;Kayu jati kualitas A';
            $content = $headers . "\n" . $example;
            $fileName = 'template_bahan_baku_' . date('Ymd') . '.csv';

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
                'message' => 'Gagal mendownload template.'
            ], 500);
        }
    }

    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
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
            ]
        ], [
            'file.required' => 'File wajib diupload.',
            'file.file' => 'File tidak valid.',
            'file.max' => 'Ukuran file maksimal 5MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            Excel::import(new MaterialsImport, $request->file('file'));
            Cache::forget('materials_all');
            
            return response()->json([
                'success' => true,
                'message' => 'Data berhasil di-import.'
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
                'errors' => $errors
            ], 422);
            
        } catch (\Exception $e) {
            Log::error('Error import material: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat import data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function export(Request $request)
    {
        try {
            $search = $request->input('search');
            
            $query = Item::with(['unit:id,name', 'category:id,name']);
            
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            }
            
            $items = $query->get();
            
            return response()->json([
                'success' => false,
                'message' => 'Fitur export belum diimplementasikan.'
            ], 501);
            
        } catch (\Exception $e) {
            Log::error('Error export material: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat export data.'
            ], 500);
        }
    }
}
