<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssemblingProduction extends Model
{
    protected $fillable = [
        'document_number',
        'date',
        'process_type',
        'ref_po_id',
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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inputs()
    {
        return $this->hasMany(AssemblingProductionInput::class);
    }

    public function outputs()
    {
        return $this->hasMany(AssemblingProductionOutput::class);
    }

    public function rejects()
    {
        return $this->hasMany(AssemblingProductionReject::class);
    }
}