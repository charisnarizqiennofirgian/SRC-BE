<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MouldingProductionOutput extends Model
{
    protected $fillable = [
        'moulding_production_id',
        'item_id',
        'qty',
    ];

    public function production()
    {
        return $this->belongsTo(MouldingProduction::class, 'moulding_production_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}