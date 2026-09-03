<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionOrderDetail extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'production_order_id',
        'sales_order_detail_id',
        'item_id',
        'qty_planned',
        'qty_produced',
        'current_stage',
        'initial_stock_snapshot',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
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
