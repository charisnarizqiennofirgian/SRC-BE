<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SawmillProductionRst extends Model
{
    protected $fillable = [
        'sawmill_production_id',
        'item_rst_id',
        'qty_rst_pcs',
        'volume_rst_m3',
    ];

    public function production()
    {
        return $this->belongsTo(SawmillProduction::class, 'sawmill_production_id');
    }

    public function itemRst()
    {
        return $this->belongsTo(Item::class, 'item_rst_id');
    }
}
