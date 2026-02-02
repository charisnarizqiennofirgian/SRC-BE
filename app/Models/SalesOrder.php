<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
    'so_number',
    'buyer_id',
    'user_id',
    'so_date',
    'delivery_date',
    'customer_po_number',
    'subtotal',
    'discount',
    'tax_ppn',
    'tax_rate',
    'grand_total',
    'notes',
    'status',
    'currency',
    'exchange_rate',
];

    protected $casts = [
        'so_date' => 'date',
        'delivery_date' => 'date',
    ];

    protected $with = [
        'details',
    ];

    public function details()
    {
        return $this->hasMany(SalesOrderDetail::class);
    }

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function productionOrders()
    {
        return $this->hasMany(ProductionOrder::class);
    }
}
