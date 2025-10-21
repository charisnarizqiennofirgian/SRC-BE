<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptDetail extends Model
{
    use HasFactory;

    
    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_detail_id', 
        'item_id',
        'quantity_ordered',
        'quantity_received',
        'billed', 
    ];

    
    protected $casts = [
        'quantity_ordered' => 'decimal:2',
        'quantity_received' => 'decimal:2',
        'billed' => 'boolean', 
    ];

    
    protected $attributes = [
        'billed' => false, 
    ];

    /**
     * RELASI KE GOODS RECEIPT (Header)
     */
    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    
    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    
    public function purchaseOrderDetail()
    {
        return $this->belongsTo(PurchaseOrderDetail::class);
    }

    
    public function purchaseBillDetail()
    {
        return $this->hasOne(PurchaseBillDetail::class);
    }
}
