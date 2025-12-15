<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SawmillProductionLog extends Model
{
    protected $fillable = [
        'sawmill_production_id',
        'item_log_id',
        'qty_log_pcs',
        'volume_log_m3', 
    ];

    public function production()
    {
        return $this->belongsTo(SawmillProduction::class, 'sawmill_production_id');
    }

    public function itemLog()
    {
        return $this->belongsTo(Item::class, 'item_log_id');
    }
}
