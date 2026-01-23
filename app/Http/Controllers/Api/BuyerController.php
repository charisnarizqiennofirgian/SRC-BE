<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BuyerController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            $query = Buyer::with('receivableAccount')->latest();

            if ($search) {
                $query->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
            }

            $buyers = $query->paginate($perPage);

            return response()->json($buyers, 200);

        } catch (\Exception $e) {
            \Log::error('Error saat mengambil data buyer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data buyer.',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'code' => 'required|string|max:255|unique:buyers',
                'name' => 'required|string|max:255',
                'address' => 'nullable|string',
                'phone' => 'nullable|string|max:20',
                'receivable_account_id' => 'nullable|exists:chart_of_accounts,id',
            ]);

            $buyer = Buyer::create($validatedData);
            $buyer->load('receivableAccount');

            return response()->json([
                'success' => true,
                'message' => 'Buyer berhasil ditambahkan.',
                'data' => $buyer
            ], 201);

        } catch (ValidationException $e) {
            \Log::error('Validation error saat menambah buyer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error saat menambah buyer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan buyer.',
            ], 500);
        }
    }

    public function update(Request $request, Buyer $buyer)
    {
        try {
            $validatedData = $request->validate([
                'code' => 'required|string|max:255|unique:buyers,code,' . $buyer->id,
                'name' => 'required|string|max:255',
                'address' => 'nullable|string',
                'phone' => 'nullable|string|max:20',
                'receivable_account_id' => 'nullable|exists:chart_of_accounts,id',
            ]);

            $buyer->update($validatedData);
            $buyer->load('receivableAccount');

            return response()->json([
                'success' => true,
                'message' => 'Buyer berhasil diperbarui.',
                'data' => $buyer
            ], 200);

        } catch (ValidationException $e) {
            \Log::error('Validation error saat update buyer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error saat update buyer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui buyer.',
            ], 500);
        }
    }

    public function destroy(Buyer $buyer)
    {
        try {
            $buyer->delete();

            return response()->json([
                'success' => true,
                'message' => 'Buyer berhasil dihapus.'
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error saat menghapus buyer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus buyer. Kemungkinan sudah terhubung dengan data lain.'
            ], 409);
        }
    }
}
