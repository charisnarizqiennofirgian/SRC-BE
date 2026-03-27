<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PembahananProduction extends Model
{
    protected $fillable = [
        'document_number',
        'date',
        'estimated_finish_date',
        'ref_po_id',
        'source_warehouse_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'date'                  => 'date',
        'estimated_finish_date' => 'date',
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

    public function items()
    {
        return $this->hasMany(PembahananProductionItem::class);
    }
}