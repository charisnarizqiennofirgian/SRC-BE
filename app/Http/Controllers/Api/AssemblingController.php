<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\Inventory;
use App\Models\Warehouse;
use App\Models\Category;
use App\Models\ProductionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class AssemblingController extends Controller
{
    /**
     * Ambil list PO yang bisa dirakit (dengan full details & item relation)
     */
    public function getAvailableOrders()
    {
        $orders = ProductionOrder::with(['details.item', 'salesOrder.buyer'])
            ->where('status', '!=', 'completed')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($po) {
                return [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'status' => $po->status,
                    'buyer_name' => $po->salesOrder?->buyer?->name,
                    'details' => $po->details->map(function ($detail) {
                        return [
                            'id' => $detail->id,
                            'item_id' => $detail->item_id,
                            'item' => [
                                'id' => $detail->item->id,
                                'name' => $detail->item->name,
                                'code' => $detail->item->code,
                            ],
                            'qty_planned' => $detail->qty_planned,
                            'qty_produced' => $detail->qty_produced,
                        ];
                    }),
                ];
            });

        return response()->json($orders);
    }

    /**
     * Cek kecukupan bahan untuk rakit
     * Validasi BOM vs Stok di Gudang Komponen
     */
    public function checkMaterialAvailability(Request $request)
    {
        $detailId = $request->detail_id;
        
        $detail = ProductionOrderDetail::with('item.bomComponents.childItem')->findOrFail($detailId);
        
        // Cari ID Gudang Komponen (MESIN)
        $warehouseKomponen = Warehouse::where('code', 'MESIN')->first();
        
        if (!$warehouseKomponen) {
            return response()->json(['error' => 'Gudang Komponen tidak ditemukan'], 404);
        }

        $components = [];
        $maxCanProduce = PHP_INT_MAX; // Mulai dari angka terbesar

        foreach ($detail->item->bomComponents as $bom) {
            // Ambil stok komponen di gudang MESIN
            $inventory = Inventory::where('warehouse_id', $warehouseKomponen->id)
                ->where('item_id', $bom->child_item_id)
                ->first();

            $stockAvailable = $inventory ? $inventory->qty_pcs : 0;
            $qtyNeeded = $bom->qty; // Kebutuhan per 1 produk
            
            // Hitung berapa maksimal bisa diproduksi dari komponen ini
            $canProduce = $qtyNeeded > 0 ? floor($stockAvailable / $qtyNeeded) : 0;
            
            // Ambil nilai terkecil (bottleneck)
            $maxCanProduce = min($maxCanProduce, $canProduce);

            $components[] = [
                'component_id' => $bom->child_item_id,
                'component_name' => $bom->childItem->name,
                'qty_needed_per_unit' => $qtyNeeded,
                'stock_available' => $stockAvailable,
                'can_produce' => $canProduce,
                'is_sufficient' => $stockAvailable >= $qtyNeeded,
            ];
        }

        return response()->json([
            'components' => $components,
            'max_can_produce' => $maxCanProduce == PHP_INT_MAX ? 0 : $maxCanProduce,
            'detail' => [
                'item_name' => $detail->item->name,
                'qty_remaining' => $detail->qty_planned - $detail->qty_produced,
            ]
        ]);
    }

    /**
     * Simpan hasil rakitan
     * Logic: Kurangi Komponen, Tambah White Body, Update Progress PO
     */
    public function store(Request $request)
    {
        $request->validate([
            'production_order_detail_id' => 'required|exists:production_order_details,id',
            'qty_good' => 'required|numeric|min:1',
            'rejected_components' => 'nullable|array',
            'rejected_components.*.item_id' => 'required_with:rejected_components|exists:items,id',
            'rejected_components.*.qty' => 'required_with:rejected_components|numeric|min:1',
        ]);

        DB::beginTransaction();

        try {
            $detail = ProductionOrderDetail::with('item.bomComponents', 'productionOrder')->findOrFail($request->production_order_detail_id);
            
            $qtyGood = $request->qty_good;
            $rejectedComponents = $request->rejected_components ?? [];

            // Ambil ID Gudang
            $warehouseKomponen = Warehouse::where('code', 'MESIN')->firstOrFail();
            $warehouseAssembling = Warehouse::where('code', 'ASSEMBLING')->firstOrFail();
            
            // Ambil ID Kategori White Body
            $categoryWhiteBody = Category::where('name', 'White Body')->firstOrFail();

            // STEP 1: Validasi Kecukupan Stok
            foreach ($detail->item->bomComponents as $bom) {
                $inventory = Inventory::where('warehouse_id', $warehouseKomponen->id)
                    ->where('item_id', $bom->child_item_id)
                    ->first();

                $stockAvailable = $inventory ? $inventory->qty_pcs : 0;
                $qtyNeeded = $bom->qty * $qtyGood;

                if ($stockAvailable < $qtyNeeded) {
                    throw new \Exception("Stok {$bom->childItem->name} tidak cukup! Butuh {$qtyNeeded}, tersedia {$stockAvailable}");
                }
            }

            // STEP 2: Kurangi Stok Komponen (Sesuai BOM)
            foreach ($detail->item->bomComponents as $bom) {
                $qtyUsed = $bom->qty * $qtyGood;

                $inventory = Inventory::where('warehouse_id', $warehouseKomponen->id)
                    ->where('item_id', $bom->child_item_id)
                    ->first();

                $inventory->decrement('qty_pcs', $qtyUsed);
            }

            // STEP 3: Kurangi Stok Komponen yang Reject/Rusak
            foreach ($rejectedComponents as $reject) {
                $inventory = Inventory::where('warehouse_id', $warehouseKomponen->id)
                    ->where('item_id', $reject['item_id'])
                    ->first();

                if ($inventory) {
                    $inventory->decrement('qty_pcs', $reject['qty']);
                }
            }

            // STEP 4: Tambah Stok White Body di Gudang Assembling
            $inventoryWhiteBody = Inventory::firstOrCreate(
                [
                    'warehouse_id' => $warehouseAssembling->id,
                    'item_id' => $detail->item_id,
                ],
                [
                    'qty_pcs' => 0,
                    'qty_m3' => 0,
                ]
            );

            $inventoryWhiteBody->increment('qty_pcs', $qtyGood);

            // STEP 5: Update Progress PO
            $detail->increment('qty_produced', $qtyGood);

            // STEP 6: Cek apakah PO sudah selesai semua
            $allCompleted = $detail->productionOrder->details->every(function ($d) {
                return $d->qty_produced >= $d->qty_planned;
            });

            if ($allCompleted) {
                $detail->productionOrder->update(['status' => 'completed_assembling']);
            }

            // STEP 7: Catat di Production Log
            ProductionLog::create([
                'date' => now(),
                'reference_number' => $detail->productionOrder->po_number,
                'process_type' => 'assembling',
                'stage' => 'assembling',
                'input_item_id' => $detail->item_id, // Komponen (simplifikasi)
                'input_quantity' => $qtyGood,
                'output_item_id' => $detail->item_id, // White Body
                'output_quantity' => $qtyGood,
                'notes' => "Assembling {$qtyGood} pcs",
                'user_id' => Auth::id(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Proses assembling berhasil!',
                'next_process' => $detail->item->production_route, // 'sanding' atau 'rustik'
                'qty_produced' => $qtyGood,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}