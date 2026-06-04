<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SawmillProductionJeblosan extends Model
{
    protected $fillable = [
        'sawmill_production_id',
        'item_jeblosan_id',
        'qty_pcs',
        'volume_m3',
        'is_sisa',
    ];

    protected $casts = [
        'qty_pcs'   => 'integer',
        'volume_m3' => 'float',
        'is_sisa'   => 'boolean',
    ];

    public function production()
    {
        return $this->belongsTo(SawmillProduction::class, 'sawmill_production_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_jeblosan_id');
    }

    public function rsts()
    {
        return $this->hasMany(SawmillProductionRst::class, 'jeblosan_id');
    }
}
