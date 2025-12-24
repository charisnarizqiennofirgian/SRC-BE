<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\Inventory;
use App\Models\ComponentMaterialRecipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OperatorMesinController extends Controller
{
    // GET /operator-mesin/po/{id}
    public function showByPo($productionOrderId)
    {
        $po = ProductionOrder::with(['details.item.bomComponents.childItem'])
            ->findOrFail($productionOrderId);

        $warehouseKomponenId = 6; // Gudang Komponen
        $warehouseMouldingId = 5; // Gudang Moulding (kayu)

        $components = [];

        foreach ($po->details as $detail) {
            $fgItem     = $detail->item;
            $qtyPlanned = (float) $detail->qty_planned;

            // 🔁 Jika item belum punya BOM, lewati saja
            if (!$fgItem || $fgItem->bomComponents->isEmpty()) {
                continue;
            }

            foreach ($fgItem->bomComponents as $bomRow) {
                $component = $bomRow->childItem;
                if (!$component) {
                    continue;
                }

                $grossNeed = $qtyPlanned * (float) $bomRow->qty;

                $stockKomponen = Inventory::where('warehouse_id', $warehouseKomponenId)
                    ->where('item_id', $component->id)
                    ->sum('qty');

                $target = max($grossNeed - $stockKomponen, 0);

                // CEK RESEP ADA/TIDAK
                $recipe = ComponentMaterialRecipe::where('component_item_id', $component->id)->first();

                $components[] = [
                    'fg_item_id'             => $fgItem->id,
                    'fg_item_name'           => $fgItem->name,
                    'component_item_id'      => $component->id,
                    'component_name'         => $component->name,
                    'gross_need'             => $grossNeed,
                    'stock_komponen'         => $stockKomponen,
                    'target_qty'             => $target,
                    'HAS_RECIPE'             => $recipe ? true : false,
                    'material_item_id'       => $recipe?->material_item_id,
                    'material_name'          => $recipe?->materialItem?->name,
                    'material_qty_per_unit'  => $recipe?->qty_per_unit,
                    'estimated_material_qty' => $target * ($recipe?->qty_per_unit ?? 0),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $components,
        ]);
    }

    // POST /operator-mesin/recipe
    public function storeRecipe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'component_item_id' => ['required', 'integer', 'exists:items,id'],
            'material_item_id'  => ['required', 'integer', 'exists:items,id'],
            'qty_per_unit'      => ['required', 'numeric', 'min:0.001'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi resep gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $exists = ComponentMaterialRecipe::where('component_item_id', $request->component_item_id)
            ->where('material_item_id', $request->material_item_id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Resep ini sudah ada.',
            ], 422);
        }

        ComponentMaterialRecipe::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Resep bahan berhasil disimpan!',
        ]);
    }

    // POST /operator-mesin/produce
    public function produce(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'production_order_id'            => ['required', 'integer', 'exists:production_orders,id'],
            'components'                     => ['required', 'array', 'min:1'],
            'components.*.item_id'           => ['required', 'integer'],
            'components.*.produced_qty'      => ['required', 'numeric', 'min:0'],
            'components.*.material_used_qty' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data                = $validator->validated();
        $warehouseMouldingId = 5; // Gudang Moulding
        $warehouseKomponenId = 6; // Gudang Komponen

        DB::beginTransaction();

        try {
            foreach ($data['components'] as $row) {
                $producedQty     = (float) $row['produced_qty'];
                $materialUsedQty = (float) $row['material_used_qty'];

                if ($producedQty <= 0) {
                    continue;
                }

                $componentItemId = (int) $row['item_id'];

                // 1) CEK & POTONG KAYU dari RESEP
                $recipe = ComponentMaterialRecipe::where('component_item_id', $componentItemId)->first();

                if (!$recipe) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Resep kayu untuk {$row['item_id']} belum diset!",
                    ], 422);
                }

                $materialItemId = $recipe->material_item_id;

                $sourceInv = Inventory::where('warehouse_id', $warehouseMouldingId)
                    ->where('item_id', $materialItemId)
                    ->lockForUpdate()
                    ->first();

                if (!$sourceInv || $sourceInv->qty < $materialUsedQty) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Stok kayu {$recipe->materialItem->name} tidak cukup!",
                    ], 422);
                }

                // Potong kayu
                $sourceInv->qty -= $materialUsedQty;
                $sourceInv->save();

                // 2) Tambah stok komponen
                $targetInv = Inventory::where('warehouse_id', $warehouseKomponenId)
                    ->where('item_id', $componentItemId)
                    ->lockForUpdate()
                    ->first();

                if ($targetInv) {
                    $targetInv->qty += $producedQty;
                    $targetInv->save();
                } else {
                    Inventory::create([
                        'warehouse_id'   => $warehouseKomponenId,
                        'item_id'        => $componentItemId,
                        'qty'            => $producedQty,
                        'ref_po_id'      => $data['production_order_id'],
                        'ref_product_id' => null,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Hasil produksi berhasil disimpan!',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
