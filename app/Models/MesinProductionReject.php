<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MesinProductionReject extends Model
{
    protected $fillable = [
        'mesin_production_id',
        'mesin_production_input_id',
        'item_id',
        'qty',
        'machine_id',
        'keterangan',
    ];

    public function production()
    {
        return $this->belongsTo(MesinProduction::class, 'mesin_production_id');
    }

    public function input()
    {
        return $this->belongsTo(MesinProductionInput::class, 'mesin_production_input_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }
}
