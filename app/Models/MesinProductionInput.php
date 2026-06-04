<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MesinProductionInput extends Model
{
    protected $fillable = [
        'mesin_production_id',
        'item_id',
        'qty',
    ];

    public function production()
    {
        return $this->belongsTo(MesinProduction::class, 'mesin_production_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function output()
    {
        return $this->hasOne(MesinProductionOutput::class, 'mesin_production_input_id');
    }

    public function reject()
    {
        return $this->hasOne(MesinProductionReject::class, 'mesin_production_input_id');
    }
}
