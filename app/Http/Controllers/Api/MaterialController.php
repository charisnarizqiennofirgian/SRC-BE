<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel; // Pastikan ini di-import
use App\Imports\MaterialsImport;     // Pastikan ini di-import

class MaterialController extends Controller
{
    /**
     * Menampilkan data bahan baku.
     */
    public function index(Request $request)
    {
        try {
            $materialCategoryIds = Category::whereIn('name', [
                'Bahan Baku', 
                'Bahan Penolong', 
                'Bahan Operasional'
            ])->pluck('id');

            if ($request->query('all')) {
                $items = Item::with(['unit', 'category'])
                             ->whereIn('category_id', $materialCategoryIds)
                             ->orderBy('name')->get();
                return response()->json(['success' => true, 'data' => $items]);
            }

            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');
            
            $query = Item::with(['unit', 'category'])->whereIn('category_id', $materialCategoryIds)->latest();
            
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            }
            
            $items = $query->paginate($perPage);
            
            return response()->json(['success' => true, 'data' => $items]);

        } catch (\Exception $e) {
            \Log::error('Error saat mengambil data bahan baku: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data bahan baku.',
            ], 500);
        }
    }

    /**
     * Menyimpan bahan baku baru.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:items,code',
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'description' => 'nullable|string',
            'stock' => 'nullable|numeric|min:0',
            'type' => 'required|in:Stok,Non-Stok'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validasi gagal.', 'errors' => $validator->errors()], 422);
        }
        
        $itemData = $validator->validated();
        $item = Item::create($itemData);
        return response()->json(['success' => true, 'message' => 'Bahan berhasil dibuat.', 'data' => $item], 201);
    }
    
    /**
     * Mengupdate data bahan baku.
     */
    public function update(Request $request, Item $material)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:items,code,' . $material->id,
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'description' => 'nullable|string',
            'stock' => 'nullable|numeric|min:0',
            'type' => 'required|in:Stok,Non-Stok'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validasi gagal.', 'errors' => $validator->errors()], 422);
        }

        $material->update($validator->validated());
        return response()->json(['success' => true, 'message' => 'Bahan berhasil diperbarui.', 'data' => $material]);
    }

    /**
     * Menghapus bahan baku.
     */
    public function destroy(Item $material)
    {
        try {
            $material->delete();
            return response()->json(['success' => true, 'message' => 'Bahan baku berhasil dihapus.']);
        } catch (\Exception $e) {
            \Log::error('Gagal menghapus bahan baku: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal menghapus. Kemungkinan data ini terhubung dengan data lain.'], 500);
        }
    }
    
    /**
     * Download template untuk import material.
     */
    public function downloadTemplate()
    {
        $headers = 'kode;nama;kategori;satuan;tipe;stok_awal;deskripsi';
        $example = 'BB-001;Kayu Jati;"Bahan Baku";"Lembar";"Stok";50;"Kayu jati kualitas A"';
        $content = $headers . "\n" . $example;
        $fileName = 'template_bahan_baku.csv';

        return response($content)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"{$fileName}\"");
    }

    /**
     * Import data material dari file Excel.
     */
    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|extensions:xlsx,xls,csv'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validasi gagal.', 'errors' => $validator->errors()], 422);
        }

        try {
            Excel::import(new MaterialsImport, $request->file('file'));
            return response()->json(['success' => true, 'message' => 'Bahan baku berhasil di-import.'], 200);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            // ... (kode error handling Anda bisa ditambahkan di sini)
            return response()->json(['success' => false, 'message' => 'Validasi file gagal.', 'errors' => $e->failures()], 422);
        } catch (\Exception $e) {
            \Log::error('Error saat import material: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat import data: ' . $e->getMessage()
            ], 500);
        }
    }
}