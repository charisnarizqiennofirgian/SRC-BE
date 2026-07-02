<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MesinProductionInput extends Model
{
    protected $fillable = [
        'mesin_production_id',
        'item_id',
        'machine_id',
        'qty',
        'finishing',
    ];

    public function production()
    {
        return $this->belongsTo(MesinProduction::class, 'mesin_production_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
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
