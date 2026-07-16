<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MouldingProduction extends Model
{
    protected $fillable = [
        'document_number',
        'date',
        'ref_po_id',
        'production_order_detail_id',
        'qty_produk_jadi',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class, 'ref_po_id');
    }

    public function productionOrderDetail()
    {
        return $this->belongsTo(ProductionOrderDetail::class, 'production_order_detail_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inputs()
    {
        return $this->hasMany(MouldingProductionInput::class);
    }

    public function outputs()
    {
        return $this->hasMany(MouldingProductionOutput::class);
    }

    public function rejects()
    {
        return $this->hasMany(MouldingProductionReject::class);
    }
}