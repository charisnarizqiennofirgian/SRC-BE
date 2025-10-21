<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailPesananPembelian extends Model
{
    use HasFactory;

    protected $table = 'purchase_order_details';

    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'quantity_ordered',
        'price',
        'subtotal',
    ];

    
    public function barang()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    
    public function pesanan()
    {
        return $this->belongsTo(PesananPembelian::class, 'purchase_order_id');
    }
}