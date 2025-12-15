<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SawmillProduction extends Model
{
    protected $fillable = [
        'document_number',
        'date',
        'warehouse_from_id',
        'warehouse_to_id',
        'notes',
        'total_log_m3',
        'total_rst_m3',
        'yield_percent',
    ];

    protected $casts = [
        'date'           => 'date',
        'total_log_m3'   => 'float',
        'total_rst_m3'   => 'float',
        'yield_percent'  => 'float',
    ];

    public function logs()
    {
        return $this->hasMany(SawmillProductionLog::class);
    }

    public function rsts()
    {
        return $this->hasMany(SawmillProductionRst::class);
    }

    public function warehouseFrom()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_from_id');
    }

    public function warehouseTo()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_to_id');
    }
}
