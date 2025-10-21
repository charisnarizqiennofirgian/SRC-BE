<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasFactory;

    protected $table = 'stock_movements';

    protected $fillable = [
        'item_id',
        'type',
        'quantity',
        'notes',
    ];

    /**
     * Setiap pergerakan stok pasti dimiliki oleh satu item.
     */
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}