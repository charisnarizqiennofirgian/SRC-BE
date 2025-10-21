<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;

    // Memberitahu model kolom mana yang boleh diisi secara massal
    protected $fillable = [
        'po_number',
        'supplier_id',
        'order_date',
        'expected_delivery_date',
        'status',
        'subtotal',
        'type',
        'ppn_percentage',
        'ppn_amount',
        'grand_total',
        'notes',
    ];

    /**
     * Relasi ke Supplier.
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
    public function receipts()
{
    return $this->hasMany(GoodsReceipt::class);
}

    /**
     * Relasi ke Detail Pesanan.
     */
    public function details()
    {
        return $this->hasMany(PurchaseOrderDetail::class);
    }
}