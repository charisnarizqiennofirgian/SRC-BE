<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MouldingProductionInput extends Model
{
    protected $fillable = [
        'moulding_production_id',
        'moulding_production_output_id',
        'item_id',
        'qty',
    ];

    public function production()
    {
        return $this->belongsTo(MouldingProduction::class, 'moulding_production_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    // The output group this input belongs to
    public function group()
    {
        return $this->belongsTo(MouldingProductionOutput::class, 'moulding_production_output_id');
    }
}
