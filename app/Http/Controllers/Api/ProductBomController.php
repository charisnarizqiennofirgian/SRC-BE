<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\ProductBomImport;
use App\Models\Category;
use App\Models\Item;
use App\Models\ProductBom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ProductBomController extends Controller
{
    // 🔹 LANGKAH 2: untuk halaman index Vue
    public function index()
    {
        $rows = ProductBom::selectRaw('parent_item_id, COUNT(*) as components_count')
            ->groupBy('parent_item_id')
            ->with('parentItem') // pastikan relasi ada di model ProductBom
            ->get()
            ->map(function ($row) {
                return [
                    'item_id'          => $row->parent_item_id,
                    'item_name'        => $row->parentItem?->name,
                    'item_code'        => $row->parentItem?->code,
                    'components_count' => (int) $row->components_count,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $rows,
        ]);
    }

    // =============================================
    // GET: Cari item untuk dropdown form BOM
    // role=parent → item kategori Produk Jadi, role=child → item kategori Komponen (keduanya
    // filter by kategori, bukan items.type — banyak item lama, dan item baru yang dibuat lewat
    // form Master Barang biasa (ProductController::store()), tidak konsisten diisi items.type-nya.
    // items.type tetap dicek via orWhereIn sebagai fallback untuk item yang sudah benar ke-tag
    // tapi kebetulan ada di kategori lain)
    // =============================================
    public function searchItems(Request $request)
    {
        $request->validate([
            'role'   => ['nullable', 'string', 'in:parent,child'],
            'search' => ['nullable', 'string'],
        ]);

        $query = Item::query();

        if ($request->role === 'parent') {
            $categoryIds = Category::whereRaw('LOWER(name) LIKE ?', ['%produk jadi%'])->pluck('id');
            $query->where(function ($q) use ($categoryIds) {
                $q->whereIn('category_id', $categoryIds)
                  ->orWhereIn('type', [Item::TYPE_FINISHED_GOOD, Item::TYPE_WIP]);
            });
        } elseif ($request->role === 'child') {
            $categoryIds = Category::whereRaw('LOWER(name) LIKE ?', ['%komponen%'])->pluck('id');
            $query->whereIn('category_id', $categoryIds);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('code', 'LIKE', "%{$search}%")
                  ->orWhere('nama_produk', 'LIKE', "%{$search}%");
            });
        }

        $items = $query->orderBy('name')->limit(50)->get(['id', 'code', 'name', 'nama_produk', 'type']);

        return response()->json([
            'success' => true,
            'data'    => $items->map(fn ($i) => [
                'item_id'     => $i->id,
                'item_code'   => $i->code,
                'item_name'   => $i->name,
                'nama_produk' => $i->nama_produk,
                'item_type'   => $i->type,
            ]),
        ]);
    }

    // =============================================
    // GET: Detail BOM satu produk (dipakai form edit & filter di Moulding/Mesin/Assembling)
    // =============================================
    public function show($itemId)
    {
        $parent = Item::find($itemId);

        if (!$parent) {
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan.',
            ], 404);
        }

        $components = ProductBom::where('parent_item_id', $itemId)
            ->with('childItem')
            ->get()
            ->map(fn ($b) => [
                'id'          => $b->id,
                'item_id'     => $b->child_item_id,
                'item_code'   => $b->childItem?->code ?? '-',
                'item_name'   => $b->childItem?->name ?? '-',
                'nama_produk' => $b->childItem?->nama_produk,
                'qty'         => (float) $b->qty,
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'item_id'    => $parent->id,
                'item_code'  => $parent->code,
                'item_name'  => $parent->name,
                'components' => $components,
            ],
        ]);
    }

    // =============================================
    // POST: Simpan (replace-all) BOM untuk satu produk
    // =============================================
    public function store(Request $request)
    {
        $data = $request->validate([
            'parent_item_id'         => ['required', 'integer', 'exists:items,id'],
            'components'             => ['required', 'array', 'min:1'],
            'components.*.item_id'   => ['required', 'integer', 'exists:items,id', 'different:parent_item_id'],
            'components.*.qty'       => ['required', 'numeric', 'min:0.0001'],
        ]);

        DB::transaction(function () use ($data) {
            ProductBom::where('parent_item_id', $data['parent_item_id'])->delete();

            foreach ($data['components'] as $component) {
                ProductBom::create([
                    'parent_item_id' => $data['parent_item_id'],
                    'child_item_id'  => $component['item_id'],
                    'qty'            => $component['qty'],
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'BOM berhasil disimpan.',
        ]);
    }

    // =============================================
    // DELETE: Hapus seluruh BOM satu produk
    // =============================================
    public function destroy($itemId)
    {
        ProductBom::where('parent_item_id', $itemId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'BOM berhasil dihapus.',
        ]);
    }

    // 🔹 LANGKAH 4: import Excel BOM
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        try {
            Excel::import(new ProductBomImport, $request->file('file'));

            return response()->json([
                'success' => true,
                'message' => 'Import BOM berhasil diproses.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error import BOM: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat import BOM: '.$e->getMessage(),
            ], 500);
        }
    }
}
