<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseBillDetail extends Model
{
    use HasFactory;

    // ✅ Nonaktifkan timestamps (created_at, updated_at)
    public $timestamps = false;

    // ✅ KOLOM YANG BOLEH DIISI
    protected $fillable = [
        'purchase_bill_id',
        'goods_receipt_detail_id',
        'item_id',
        'quantity',
        'price',
        'subtotal',
        'specifications', 
    ];

    
    protected $casts = [
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'specifications' => 'array', 
    ];

    /**
     * RELASI KE PURCHASE BILL (Header Faktur)
     */
    public function purchaseBill()
    {
        return $this->belongsTo(PurchaseBill::class);
    }

    /**
     * RELASI KE ITEM (Barang)
     */
    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    
    public function goodsReceiptDetail()
    {
        return $this->belongsTo(GoodsReceiptDetail::class);
    }
}
