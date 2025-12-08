<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'do_number',
        'sales_order_id',
        'buyer_id',
        'user_id',
        'delivery_date',
        'status',
        'notes',
        'driver_name',
        'vehicle_number',

        
        'shipment_mode',

        // Kolom Ekspor
        'incoterm',
        'freight_terms',
        'container_number',
        'seal_number',
        'bl_date',
        'vessel_name',
        'mother_vessel',
        'consignee_info',
        'applicant_info',
        'notify_info',
        'eu_factory_number',
        'port_of_loading',
        'port_of_discharge',
        'final_destination',
        'bl_number',
        'rex_info',
        'rex_date',
        'rex_certificate_file',
        'goods_description',
        'barcode_image',

        
        'forwarder_name',    
        'peb_number',        
        'container_type',    
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'bl_date' => 'date',

        // Aturan untuk kolom JSON
        'consignee_info' => 'array',
        'applicant_info' => 'array',
        'notify_info' => 'array',
    ];

    protected $with = [
        'details',
    ];

    /**
     * Relasi: Mendapatkan baris-baris barang (details) dari pengiriman ini.
     */
    public function details()
    {
        return $this->hasMany(DeliveryOrderDetail::class);
    }

    /**
     * Relasi: Mendapatkan Pesanan Penjualan (SO) induknya.
     */
    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * Relasi: Mendapatkan data Customer (Buyer).
     */
    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    /**
     * Relasi: Mendapatkan data Admin (User) yang membuat.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
