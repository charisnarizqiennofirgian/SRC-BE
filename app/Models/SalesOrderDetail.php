<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrderDetail extends Model
{
    use HasFactory, SoftDeletes;

    
    public $timestamps = false;

    
   protected $fillable = [
    'sales_order_id',
    'item_id',
    'quantity',
    'quantity_shipped',
    'item_name',
    'item_unit',
    'item_code',        
    'unit_price',
    'discount',
    'line_total',
    'specifications',
    'delivery_date',    
    'keterangan',       
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
        return $this->belongsTo(Item::class)->select('id', 'name', 'code', 'unit_id', 'hs_code', 'nw_per_box', 'gw_per_box', 'm3_per_carton', 'wood_consumed_per_pcs');
    }

    /**
     * Cari baris detail yang AKTIF sekarang untuk kombinasi SO+item ini. Dipakai di
     * DeliveryOrderController/InvoiceService karena delivery_order_details.sales_order_detail_id
     * bisa nyangkut ke baris yang sudah soft-deleted (SO diedit setelah DO dibuat — lihat
     * migration 2026_07_03_000001_add_soft_deletes_to_sales_order_details). sales_order_id
     * tidak berubah walau baris lama di-soft-delete, jadi dipakai sebagai kunci pencarian.
     */
    public static function resolveCurrent(int $salesOrderId, int $itemId): ?self
    {
        return static::where('sales_order_id', $salesOrderId)
            ->where('item_id', $itemId)
            ->first();
    }
}