<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item; // 
use App\Models\Category; // 
use Illuminate\Http\Request;
use App\Imports\ProductsImport;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Validators\ValidationException;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        // Cari ID untuk kategori 'Produk Jadi'. Asumsi namanya persis seperti ini.
        $productCategoryId = Category::where('name', 'Produk Jadi')->value('id');
        if (!$productCategoryId) {
            // Jika kategori 'Produk Jadi' tidak ditemukan, kembalikan data kosong
            return response()->json(['success' => true, 'data' => []]);
        }

        // Jika ada permintaan ?all=true (untuk dropdown)
        if ($request->query('all')) {
            $items = Item::with(['unit', 'category'])
                ->where('category_id', $productCategoryId)
                ->orderBy('name')->get();
            return response()->json(['success' => true, 'data' => $items]);
        }

        // Untuk tabel data dengan pagination dan search
        $query = Item::with(['unit', 'category'])->where('category_id', $productCategoryId);

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $items = $query->paginate(15);
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function store(Request $request)
    {
        // Cari ID untuk kategori 'Produk Jadi' dan tambahkan ke request
        $productCategoryId = Category::where('name', 'Produk Jadi')->value('id');
        $request->merge(['category_id' => $productCategoryId]);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:items,code',
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'description' => 'nullable|string',
            'stock' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $itemData = $validator->validated();
        $itemData['stock'] = $itemData['stock'] ?? 0;

        $item = Item::create($itemData);
        return response()->json(['success' => true, 'message' => 'Produk Jadi berhasil dibuat.', 'data' => $item], 201);
    }

    public function update(Request $request, Item $product) // <-- GANTI: Gunakan model Item
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:items,code,' . $product->id, // Validasi ke tabel items
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'description' => 'nullable|string',
            'stock' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $product->update($validator->validated());
        return response()->json(['success' => true, 'message' => 'Produk Jadi berhasil diperbarui.', 'data' => $product]);
    }

    public function destroy(Item $product) // <-- GANTI: Gunakan model Item
    {
        $product->delete();
        return response()->json(['success' => true, 'message' => 'Produk Jadi berhasil dihapus.']);
    }

    public function import(Request $request)
    {
        // Fungsi ini juga perlu diubah agar mengimpor ke tabel 'items'
        // Kita perlu mengubah logika di dalam file ProductsImport.php
        $request->validate([
            'file' => 'required|extensions:xlsx,xls,csv'
        ]);

        try {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            $readerType = $extension === 'xlsx' ? \Maatwebsite\Excel\Excel::XLSX : \Maatwebsite\Excel\Excel::XLS;

            Excel::import(new ProductsImport(), $file, null, $readerType);

            Cache::forget('products_all');

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil di-import.',
            ], 200);
        } catch (ValidationException $e) {
            // ... (error handling Anda sudah bagus)
        }
    }
}