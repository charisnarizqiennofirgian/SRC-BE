<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceipt extends Model
{
    use HasFactory, SoftDeletes;

    // 
    protected $fillable = [
        'receipt_number',
        'purchase_order_id',
        'receipt_date',
        'supplier_document_number',
        'status',
        'notes',
    ];

    // Relasi ke Purchase Order
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    // Relasi ke Detail Penerimaan
    public function details()
    {
        return $this->hasMany(GoodsReceiptDetail::class);
    }
}