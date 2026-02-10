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
        'hs_code',
        'quantity_shipped',
        'quantity_boxes',
        'quantity_crates',
        'nw_per_box',
        'gw_per_box',
        'm3_per_carton',
        'wood_consumed_per_pcs',
        'total_nw',
        'total_gw',
        'total_m3',
        'total_wood_consumed',
    ];

    protected $casts = [
        'quantity_shipped' => 'decimal:4',
        'quantity_boxes' => 'integer',
        'quantity_crates' => 'integer',
        'nw_per_box' => 'decimal:2',
        'gw_per_box' => 'decimal:2',
        'm3_per_carton' => 'decimal:4',
        'wood_consumed_per_pcs' => 'decimal:4',
        'total_nw' => 'decimal:2',
        'total_gw' => 'decimal:2',
        'total_m3' => 'decimal:4',
        'total_wood_consumed' => 'decimal:4',
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

    public function calculateTotals()
    {
        if ($this->nw_per_box && $this->quantity_boxes) {
            $this->total_nw = $this->nw_per_box * $this->quantity_boxes;
        }

        if ($this->gw_per_box && $this->quantity_boxes) {
            $this->total_gw = $this->gw_per_box * $this->quantity_boxes;
        }

        if ($this->m3_per_carton && $this->quantity_boxes) {
            $this->total_m3 = $this->m3_per_carton * $this->quantity_boxes;
        }

        if ($this->wood_consumed_per_pcs && $this->quantity_shipped) {
            $this->total_wood_consumed = $this->wood_consumed_per_pcs * $this->quantity_shipped;
        }
    }
}