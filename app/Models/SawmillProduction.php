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
    ];

    protected $casts = [
        'date' => 'date',
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
