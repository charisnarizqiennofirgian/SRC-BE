<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = [
        'warehouse_id',
        'item_id',
        'qty',              //  PAKAI 'qty' (sesuai database)
        'ref_po_id',
        'ref_product_id',
    ];

    // Relations
    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    // ===== HELPER METHODS (STOK GLOBAL) =====

    /**
     * Kurangi stok item secara otomatis (FIFO - ambil dari inventory yang paling lama)
     */
    public static function decrementGlobalStock($itemId, $qty)
    {
        // Ambil semua inventory untuk item ini yang ada stok (urut FIFO)
        $inventories = self::where('item_id', $itemId)
            ->where('qty', '>', 0)
            ->orderBy('created_at', 'asc') // FIFO (First In First Out)
            ->lockForUpdate()
            ->get();

        $totalAvailable = $inventories->sum('qty');

        if ($totalAvailable < $qty) {
            throw new \Exception("Stok item ID {$itemId} tidak cukup! (Tersedia: {$totalAvailable}, Butuh: {$qty})");
        }

        $remaining = $qty;

        foreach ($inventories as $inventory) {
            if ($remaining <= 0) break;

            $toDeduct = min($remaining, $inventory->qty);

            $inventory->decrement('qty', $toDeduct);

            $remaining -= $toDeduct;
        }
    }

    /**
     * Tambah stok item ke gudang tertentu
     */
    public static function incrementGlobalStock($warehouseId, $itemId, $qty, $refPoId = null, $refProductId = null)
    {
        $inventory = self::firstOrCreate(
            [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
            ],
            [
                'qty' => 0,
                'ref_po_id' => $refPoId,
                'ref_product_id' => $refProductId,
            ]
        );

        $inventory->increment('qty', $qty);

        return $inventory;
    }

    /**
     * Get total stok global untuk item tertentu
     */
    public static function getTotalStock($itemId)
    {
        return self::where('item_id', $itemId)->sum('qty');
    }
}
