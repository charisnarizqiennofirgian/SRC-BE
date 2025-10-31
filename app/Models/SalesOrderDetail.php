<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesOrderDetail extends Model
{
    use HasFactory;

    
    public $timestamps = false;

    
    protected $fillable = [
        'sales_order_id',
        'item_id',
        'quantity',
        'quantity_shipped',
        'item_name',
        'item_unit',
        'unit_price',
        'discount',
        'line_total',
        'specifications',
    ];

    
    protected $casts = [
        'specifications' => 'array',
    ];

    
    protected $with = [
        'item',
    ];

    
    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    
    public function item()
    {
        
        return $this->belongsTo(Item::class)->select('id', 'name', 'code', 'stock');
    }
}