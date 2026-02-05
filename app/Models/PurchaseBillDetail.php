<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseBillDetail extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'purchase_bill_id',
        'goods_receipt_detail_id',
        'item_id',
        'quantity',
        'price',
        'subtotal',
        'specifications',
        // ❌ HAPUS: 'account_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'specifications' => 'array',
    ];

    public function purchaseBill()
    {
        return $this->belongsTo(PurchaseBill::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function goodsReceiptDetail()
    {
        return $this->belongsTo(GoodsReceiptDetail::class);
    }

    // ❌ HAPUS: Relationship account()
    // public function account()
    // {
    //     return $this->belongsTo(ChartOfAccount::class, 'account_id');
    // }
}
