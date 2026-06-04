<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SawmillProduction extends Model
{
    protected $fillable = [
        'document_number',
        'date',
        'estimated_finish_date',
        'warehouse_from_id',
        'warehouse_to_id',
        'notes',
        'ref_po_id',
        'ref_product_id',
        'total_log_m3',
        'total_rst_m3',
        'yield_percent',
    ];

    protected $casts = [
        'date'                  => 'date',
        'estimated_finish_date' => 'date',
        'total_log_m3'          => 'float',
        'total_rst_m3'          => 'float',
        'yield_percent'         => 'float',
    ];

    public function logs()
    {
        return $this->hasMany(SawmillProductionLog::class);
    }

    public function jeblosans()
    {
        return $this->hasMany(SawmillProductionJeblosan::class);
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
