<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PesananPembelian extends Model
{
    use HasFactory;

    
    protected $table = 'purchase_orders';

    
    protected $fillable = [
        'po_number',
        'supplier_id',
        'order_date',
        'expected_delivery_date',
        'status',
        'total_amount',
        'notes',
    ];

    
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    
    public function detail()
    {
        return $this->hasMany(DetailPesananPembelian::class, 'purchase_order_id');
    }
}