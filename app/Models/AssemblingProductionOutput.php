<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssemblingProductionOutput extends Model
{
    protected $fillable = [
        'assembling_production_id',
        'item_id',
        'qty',
    ];

    public function production()
    {
        return $this->belongsTo(AssemblingProduction::class, 'assembling_production_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}