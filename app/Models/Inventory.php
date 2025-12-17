<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = [
        'warehouse_id',
        'item_id',
        'qty',
        'ref_po_id',
        'ref_product_id',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
