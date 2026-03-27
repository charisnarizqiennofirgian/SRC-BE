<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MesinProduction extends Model
{
    protected $fillable = [
        'document_number',
        'date',
        'ref_po_id',
        'machine_id',
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

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inputs()
    {
        return $this->hasMany(MesinProductionInput::class);
    }

    public function outputs()
    {
        return $this->hasMany(MesinProductionOutput::class);
    }

    public function rejects()
    {
        return $this->hasMany(MesinProductionReject::class);
    }
}