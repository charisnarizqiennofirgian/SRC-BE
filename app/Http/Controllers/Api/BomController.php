<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\BomDetail;
use App\Models\Item;
use App\Models\StockMovement; // (IMPORT BARU UNTUK POTONG STOK)
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BomController extends Controller
{
    
    public function index(Request $request)
    {
        try {
            $query = Bom::with('product.unit');

            if ($request->has('search')) {
                $query->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('code', 'like', '%' . $request->search . '%')
                    ->orWhereHas('product', function ($q) use ($request) {
                        $q->where('name', 'like', '%' . $request->search . '%');
                    });
            }

            $boms = $query->latest()->paginate(25);

            return response()->json(['success' => true, 'data' => $boms]);
        } catch (\Exception $e) {
            Log::error('Error fetching BOMs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data BOM.'
            ], 500);
        }
    }

    
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|exists:items,id|unique:boms,item_id,NULL,id,deleted_at,NULL',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:boms,code,NULL,id,deleted_at,NULL',
            'details' => 'required|array|min:1',
            'details.*.component_item_id' => 'required|exists:items,id',
            'details.*.quantity' => 'required|numeric|min:0.0001',
            'details.*.notes' => 'nullable|string',
        ], [
            'item_id.required' => 'Produk Jadi wajib dipilih.',
            'item_id.unique' => 'Produk Jadi ini sudah memiliki Resep (BOM).',
            'name.required' => 'Nama Resep wajib diisi.',
            'details.required' => 'Bahan (detail) resep wajib diisi.',
            'details.*.component_item_id.required' => 'Komponen bahan baku wajib dipilih.',
            'details.*.quantity.required' => 'Kuantitas bahan wajib diisi.',
            'details.*.quantity.min' => 'Kuantitas bahan harus lebih besar dari 0.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $bom = Bom::create([
                'item_id' => $request->item_id,
                'name' => $request->name,
                'code' => $request->code,
                'description' => $request->description,
            ]);

            foreach ($request->details as $detail) {
                BomDetail::create([
                    'bom_id' => $bom->id,
                    'component_item_id' => $detail['component_item_id'],
                    'quantity' => $detail['quantity'],
                    'notes' => $detail['notes'] ?? null,
                ]);
            }

            $this->recalculateBomTotals($bom);
            
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Resep (BOM) berhasil disimpan.',
                'data' => $bom->load('product.unit', 'details.component')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error storing BOM: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan resep: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function show(string $id)
    {
        try {
            $bom = Bom::with([
                'product.unit', 
                'details.component.unit',
                'details.component.category'
            ])->findOrFail($id);

            return response()->json(['success' => true, 'data' => $bom]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Data resep (BOM) tidak ditemukan.'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching BOM details: ' . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => 'Gagal mengambil detail resep.'
            ], 500);
        }
    }

    
    public function update(Request $request, string $id)
    {
        try {
            $bom = Bom::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Data resep (BOM) tidak ditemukan.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'item_id' => [
                'required',
                'exists:items,id',
                Rule::unique('boms', 'item_id')->ignore($bom->id)->whereNull('deleted_at')
            ],
            'name' => 'required|string|max:255',
            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('boms', 'code')->ignore($bom->id)->whereNull('deleted_at')
            ],
            'details' => 'required|array|min:1',
            'details.*.component_item_id' => 'required|exists:items,id',
            'details.*.quantity' => 'required|numeric|min:0.0001',
            'details.*.notes' => 'nullable|string',
        ], [
            'item_id.required' => 'Produk Jadi wajib dipilih.',
            'item_id.unique' => 'Produk Jadi ini sudah memiliki Resep (BOM).',
            'name.required' => 'Nama Resep wajib diisi.',
            'details.required' => 'Bahan (detail) resep wajib diisi.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $bom->update([
                'item_id' => $request->item_id,
                'name' => $request->name,
                'code' => $request->code,
                'description' => $request->description,
            ]);

            $bom->details()->delete();

            foreach ($request->details as $detail) {
                BomDetail::create([
                    'bom_id' => $bom->id,
                    'component_item_id' => $detail['component_item_id'],
                    'quantity' => $detail['quantity'],
                    'notes' => $detail['notes'] ?? null,
                ]);
            }

            $this->recalculateBomTotals($bom);
            
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Resep (BOM) berhasil diperbarui.',
                'data' => $bom->load('product.unit', 'details.component')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating BOM: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui resep: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function destroy(string $id)
    {
        try {
            $bom = Bom::findOrFail($id);
            
            DB::beginTransaction();
            
            $productItem = $bom->product;
            
            $bom->details()->delete();
            $bom->delete();

            if ($productItem) {
                $productItem->wood_consumed_per_pcs = 0;
                $productItem->save();
            }

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Resep (BOM) berhasil dihapus.']);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Data resep (BOM) tidak ditemukan.'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting BOM: ' . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => 'Gagal menghapus resep.'
            ], 500);
        }
    }

    
    private function recalculateBomTotals(Bom $bom)
    {
        $totalWood = 0;
        
        $details = $bom->details()->with('component.category')->get();

        foreach ($details as $detail) {
            $component = $detail->component;
            if (!$component) continue;

            if ($component->category) {
                $categoryName = strtolower($component->category->name);

                if (str_contains($categoryName, 'kayu rst')) {
                    $specs = $component->specifications;
                    
                    if (is_array($specs) && isset($specs['p'], $specs['l'], $specs['t'])) {
                        $p = (float) $specs['p'];
                        $l = (float) $specs['l'];
                        $t = (float) $specs['t'];
                        
                        $m3_per_component = ($p * $l * $t) / 1000000000;
                        
                        $totalWood += $m3_per_component * $detail->quantity;
                    }
                }
            }
        }

        $bom->total_wood_m3 = $totalWood;
        $bom->save();

        $productItem = $bom->product;
        if ($productItem) {
            $productItem->wood_consumed_per_pcs = $totalWood;
            $productItem->save();
        }
    }


    // --- FUNGSI BARU (LOGIKA "DAPUR" ANDA) ---
    public function executeProduction(Request $request, Bom $bom)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|numeric|min:1',
        ], [
            'quantity.required' => 'Jumlah produksi wajib diisi.',
            'quantity.min' => 'Jumlah produksi minimal 1.',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validasi gagal.', 'errors' => $validator->errors()], 422);
        }

        $productionQty = (float) $request->quantity;

        DB::beginTransaction();
        try {
            $bom->load('details.component', 'product');

            $product = $bom->product;
            if (!$product) {
                throw new \Exception('Produk Jadi untuk resep ini tidak ditemukan.');
            }

            // 1. Cek Stok Komponen (Bahan Baku)
            foreach ($bom->details as $detail) {
                $component = $detail->component;
                $requiredQty = (float) $detail->quantity * $productionQty;

                if ($component->stock < $requiredQty) {
                    throw new \Exception("Stok {$component->name} tidak cukup. Dibutuhkan: {$requiredQty}, Tersedia: {$component->stock}");
                }
            }

            // 2. Potong Stok Komponen (Bahan Baku)
            foreach ($bom->details as $detail) {
                $component = $detail->component;
                $qtyToCut = (float) $detail->quantity * $productionQty;
                
                $oldStock = $component->stock;
                $component->stock -= $qtyToCut;
                $component->save();

                StockMovement::create([
                    'item_id' => $component->id,
                    'type' => 'Produksi (Keluar)',
                    'quantity' => -$qtyToCut,
                    'notes' => "Produksi {$product->name} (BOM ID: {$bom->id}) sebanyak {$productionQty} unit.",
                    'old_stock' => $oldStock,
                    'new_stock' => $component->stock,
                ]);
            }

            // 3. Tambah Stok Produk Jadi
            $oldStock = $product->stock;
            $product->stock += $productionQty;
            $product->save();

            StockMovement::create([
                'item_id' => $product->id,
                'type' => 'Produksi (Masuk)',
                'quantity' => $productionQty,
                'notes' => "Hasil produksi (BOM ID: {$bom->id}) sebanyak {$productionQty} unit.",
                'old_stock' => $oldStock,
                'new_stock' => $product->stock,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Produksi {$product->name} sebanyak {$productionQty} unit berhasil dicatat. Stok telah diperbarui."
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error executing production: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}