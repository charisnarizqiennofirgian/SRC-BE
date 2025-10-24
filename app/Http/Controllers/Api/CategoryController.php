<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');
            
            $query = Category::query();
            
            if ($search) {
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
            }
            
            $categories = $query->paginate($perPage);
            
            return response()->json($categories, 200);
            
        } catch (\Exception $e) {
            \Log::error('Error saat mengambil data kategori: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data kategori.',
            ], 500);
        }
    }

    public function all()
    {
        try {
            $categories = Category::orderBy('name')->get();
            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            \Log::error('Error saat mengambil semua data kategori: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data kategori.',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:categories,name',
                'description' => 'nullable|string',
            ]);

            $category = Category::create($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Kategori berhasil ditambahkan.',
                'data' => $category
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation error saat menambah kategori: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->errors()['name'][0] ?? 'Gagal menambahkan kategori. Data tidak valid.',
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error saat menambah kategori: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan kategori.',
            ], 500);
        }
    }

    public function update(Request $request, Category $category)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:categories,name,' . $category->id,
                'description' => 'nullable|string',
            ]);

            $category->update($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Kategori berhasil diperbarui.',
                'data' => $category
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->errors()['name'][0] ?? 'Gagal memperbarui kategori. Data tidak valid.',
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error saat update kategori: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui kategori.',
            ], 500);
        }
    }

    public function destroy(Category $category)
    {
        try {
            $isUsed = \App\Models\Item::where('category_id', $category->id)->exists();
            
            if ($isUsed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal menghapus. Kategori ini sedang digunakan oleh Master Barang.'
                ], 409); // 409 Conflict
            }

            $category->delete();

            return response()->json([
                'success' => true,
                'message' => 'Kategori berhasil dihapus.'
            ], 200);
            
        } catch (\Exception $e) {
            \Log::error('Error saat menghapus kategori: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus kategori.',
            ], 500);
        }
    }
}