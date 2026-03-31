<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RustikKomponenProduction extends Model
{
    protected $fillable = [
        'document_number',
        'date',
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
        return $this->hasMany(RustikKomponenInput::class);
    }

    public function outputs()
    {
        return $this->hasMany(RustikKomponenOutput::class);
    }

    public function rejects()
    {
        return $this->hasMany(RustikKomponenReject::class);
    }
}