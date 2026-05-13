<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnyamProduction extends Model
{
    protected $fillable = [
        'document_number',
        'date',
        'ref_po_id',
        'notes',
        'created_by',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class, 'ref_po_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}