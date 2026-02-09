<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = [
        'warehouse_id',
        'item_id',
        'qty_pcs',
        'qty_m3',
        'ref_po_id',
        'ref_product_id',
    ];

    protected $appends = ['qty'];

    public function getQtyAttribute()
    {
        return $this->qty_pcs;
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public static function decrementGlobalStock($itemId, $qty)
    {
        $inventories = self::where('item_id', $itemId)
            ->where('qty_pcs', '>', 0)
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->get();

        $totalAvailable = $inventories->sum('qty_pcs');

        if ($totalAvailable < $qty) {
            throw new \Exception("Stok item ID {$itemId} tidak cukup! (Tersedia: {$totalAvailable}, Butuh: {$qty})");
        }

        $remaining = $qty;

        foreach ($inventories as $inventory) {
            if ($remaining <= 0)
                break;

            $toDeduct = min($remaining, $inventory->qty_pcs);

            $inventory->decrement('qty_pcs', $toDeduct);

            $remaining -= $toDeduct;
        }
    }

    public static function incrementGlobalStock($warehouseId, $itemId, $qty, $refPoId = null, $refProductId = null)
    {
        $inventory = self::firstOrCreate(
            [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
            ],
            [
                'qty_pcs' => 0,
                'qty_m3' => 0,
                'ref_po_id' => $refPoId,
                'ref_product_id' => $refProductId,
            ]
        );

        $inventory->increment('qty_pcs', $qty);

        return $inventory;
    }

    public static function getTotalStock($itemId)
    {
        return self::where('item_id', $itemId)->sum('qty_pcs');
    }
}