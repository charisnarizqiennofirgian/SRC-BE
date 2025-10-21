<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseBill extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Properti yang boleh diisi secara massal.
     */
    protected $fillable = [
        'supplier_id',
        'bill_number',
        'supplier_invoice_number',
        'bill_date',
        'due_date',
        'subtotal',
        'ppn_amount',
        'total_amount',
        'status',
        'notes',
    ];

    /**
     * Relasi ke Supplier.
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Relasi ke Detail Faktur Pembelian.
     */
    public function details()
    {
        return $this->hasMany(PurchaseBillDetail::class);
    }
}