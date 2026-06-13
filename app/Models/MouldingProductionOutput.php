<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MouldingProductionOutput extends Model
{
    protected $fillable = [
        'moulding_production_id',
        'moulding_production_input_id',
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

    // N RST inputs that produced this output (group relationship)
    public function inputs()
    {
        return $this->hasMany(MouldingProductionInput::class, 'moulding_production_output_id');
    }

    public function rejects()
    {
        return $this->hasMany(MouldingProductionReject::class, 'moulding_production_output_id');
    }
}
