<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderDetail extends Model
{
    use HasFactory;

    /**
     * Properti yang boleh diisi secara massal.
     */
    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'quantity_ordered',
        'price',
        'subtotal',
        'specifications', 
    ];

    
    protected $casts = [
        'specifications' => 'array',
    ];


    /**
     * Relasi ke Item (Barang).
     */
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
    public function specifications()
{
    return $this->hasOne(ItemSpecification::class, 'purchase_order_detail_id');
}
}