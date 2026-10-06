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
        'moulding_completed_at',
        'moulding_completed_by',
        'mesin_completed_at',
        'mesin_completed_by',
    ];

    protected $casts = [
        'moulding_completed_at' => 'datetime',
        'mesin_completed_at'    => 'datetime',
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

    public function mouldingCompletedBy()
    {
        return $this->belongsTo(User::class, 'moulding_completed_by');
    }

    public function mesinCompletedBy()
    {
        return $this->belongsTo(User::class, 'mesin_completed_by');
    }
}
