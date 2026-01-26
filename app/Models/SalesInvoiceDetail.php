<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesInvoiceDetail extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sales_invoice_id',
        'sales_order_detail_id',
        'delivery_order_detail_id',
        'item_id',
        'item_name',
        'item_code',
        'item_unit',
        'quantity',
        'unit_price_original',
        'discount_original',
        'subtotal_original',
        'unit_price_idr',
        'discount_idr',
        'subtotal_idr',
        'unit_cost',
        'total_cost',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price_original' => 'decimal:2',
        'discount_original' => 'decimal:2',
        'subtotal_original' => 'decimal:2',
        'unit_price_idr' => 'decimal:2',
        'discount_idr' => 'decimal:2',
        'subtotal_idr' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    protected $with = [
        'item',
    ];

    /**
     * Relationship ke Sales Invoice
     */
    public function salesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    /**
     * Relationship ke Sales Order Detail
     */
    public function salesOrderDetail()
    {
        return $this->belongsTo(SalesOrderDetail::class);
    }

    /**
     * Relationship ke Delivery Order Detail
     */
    public function deliveryOrderDetail()
    {
        return $this->belongsTo(DeliveryOrderDetail::class);
    }

    /**
     * Relationship ke Item
     */
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
