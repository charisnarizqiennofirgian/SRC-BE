<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UnitController extends Controller
{
    /**
     * Menampilkan semua satuan (untuk dropdown).
     */
    public function all()
    {
        try {
            $units = Unit::orderBy('name')->get();
            return response()->json([
                'success' => true,
                'data' => $units
            ]);
        } catch (\Exception $e) {
            \Log::error('Error saat mengambil semua satuan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data.',
            ], 500);
        }
    }

    /**
     * Menampilkan data satuan dengan pagination dan search (untuk tabel).
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            $query = Unit::query();

            if ($search) {
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('short_name', 'like', "%{$search}%");
            }

            $units = $query->latest()->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $units
            ]);

        } catch (\Exception $e) {
            \Log::error('Error saat mengambil data satuan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data.',
            ], 500);
        }
    }

    /**
     * Menampilkan detail satuan.
     */
    public function show(Unit $unit)
    {
        return response()->json([
            'success' => true,
            'data' => $unit
        ]);
    }

    /**
     * Menyimpan satuan baru.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:units,name',
            'short_name' => 'required|string|max:50|unique:units,short_name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $unit = Unit::create($validator->validated());
            return response()->json([
                'success' => true,
                'message' => 'Satuan berhasil ditambahkan.',
                'data' => $unit
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Error saat menambah satuan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan data.',
            ], 500);
        }
    }

    /**
     * Mengupdate satuan yang sudah ada.
     */
    public function update(Request $request, Unit $unit)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:units,name,' . $unit->id,
            'short_name' => 'required|string|max:50|unique:units,short_name,' . $unit->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $unit->update($validator->validated());
            return response()->json([
                'success' => true,
                'message' => 'Satuan berhasil diperbarui.',
                'data' => $unit
            ]);
        } catch (\Exception $e) {
            \Log::error('Error saat update satuan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui data.',
            ], 500);
        }
    }

    /**
     * Menghapus satuan.
     */
    public function destroy(Unit $unit)
    {
        try {
            $unit->delete();
            return response()->json([
                'success' => true,
                'message' => 'Satuan berhasil dihapus.'
            ]);
        } catch (\Exception $e) {
            \Log::error('Gagal menghapus satuan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus. Kemungkinan satuan ini masih digunakan oleh data lain.'
            ], 500);
        }
    }
}
