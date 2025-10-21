<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenerimaanBarang extends Model
{
    use HasFactory;

    protected $table = 'goods_receipts';

    protected $fillable = [
        'receipt_number',
        'purchase_order_id',
        'item_id',
        'receipt_date',
        'quantity_received',
        'notes',
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