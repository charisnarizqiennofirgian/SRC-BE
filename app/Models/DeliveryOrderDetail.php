<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryOrderDetail extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'delivery_order_id',
        'sales_order_detail_id',
        'item_id',
        'item_name',
        'item_unit',
        'quantity_shipped',
        'quantity_boxes',

        
        'quantity_crates',   
    ];

    public function deliveryOrder()
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function salesOrderDetail()
    {
        return $this->belongsTo(SalesOrderDetail::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
