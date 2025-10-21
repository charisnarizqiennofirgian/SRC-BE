<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    /**
     * Menampilkan semua data supplier dengan pagination.
     */
    public function index(Request $request)
    {
        try {
            // Ambil parameter dari request
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');
            
            // Query builder dengan latest
            $query = Supplier::latest();
            
            // Jika ada parameter search
            if ($search) {
                $query->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
            }
            
            // Paginate hasil query
            $suppliers = $query->paginate($perPage);
            
            return response()->json($suppliers, 200);
            
        } catch (\Exception $e) {
            \Log::error('Error saat mengambil data supplier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data supplier.',
            ], 500);
        }
    }

    /**
     * Menyimpan supplier baru.
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'code' => 'required|string|max:255|unique:suppliers',
                'name' => 'required|string|max:255',
                'address' => 'nullable|string',
                'phone' => 'nullable|string|max:20',
            ]);

            $supplier = Supplier::create($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Supplier berhasil ditambahkan.',
                'data' => $supplier
            ], 201);

        } catch (ValidationException $e) {
            \Log::error('Validation error saat menambah supplier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error saat menambah supplier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan supplier.',
            ], 500);
        }
    }

    /**
     * Mengupdate data supplier.
     */
    public function update(Request $request, Supplier $supplier)
    {
        try {
            $validatedData = $request->validate([
                'code' => 'required|string|max:255|unique:suppliers,code,' . $supplier->id,
                'name' => 'required|string|max:255',
                'address' => 'nullable|string',
                'phone' => 'nullable|string|max:20',
            ]);

            $supplier->update($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Supplier berhasil diperbarui.',
                'data' => $supplier
            ], 200);

        } catch (ValidationException $e) {
            \Log::error('Validation error saat update supplier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error saat update supplier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui supplier.',
            ], 500);
        }
    }

    /**
     * Menghapus supplier.
     */
    public function destroy(Supplier $supplier)
    {
        try {
            $supplier->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Supplier berhasil dihapus.'
            ], 200);
            
        } catch (\Exception $e) {
            \Log::error('Error saat menghapus supplier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus supplier. Kemungkinan sudah terhubung dengan data lain.'
            ], 409);
        }
    }
}