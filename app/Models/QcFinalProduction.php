<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QcFinalProduction extends Model
{
    protected $fillable = [
        'document_number',
        'date',
        'ref_po_id',
        'source_warehouse_id',
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

    public function sourceWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function passedItems()
    {
        return $this->hasMany(QcFinalPassedItem::class);
    }

    public function rejectItems()
    {
        return $this->hasMany(QcFinalRejectItem::class);
    }
}