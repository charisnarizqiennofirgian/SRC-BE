<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SawmillProductionRst extends Model
{
    protected $fillable = [
        'sawmill_production_id',
        'jeblosan_id',
        'item_rst_id',
        'qty_rst_pcs',
        'volume_rst_m3',
        'is_sisa',
        'destination_warehouse_id',
    ];

    protected $casts = [
        'qty_rst_pcs'   => 'integer',
        'volume_rst_m3' => 'float',
        'is_sisa'       => 'boolean',
    ];

    public function production()
    {
        return $this->belongsTo(SawmillProduction::class, 'sawmill_production_id');
    }

    public function jeblosan()
    {
        return $this->belongsTo(SawmillProductionJeblosan::class, 'jeblosan_id');
    }

    public function itemRst()
    {
        return $this->belongsTo(Item::class, 'item_rst_id');
    }
}
