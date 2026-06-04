<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MouldingProductionReject extends Model
{
    protected $fillable = [
        'moulding_production_id',
        'moulding_production_input_id',
        'item_id',
        'qty',
        'reject_type',
        'keterangan',
    ];

    public function production()
    {
        return $this->belongsTo(MouldingProduction::class, 'moulding_production_id');
    }

    public function input()
    {
        return $this->belongsTo(MouldingProductionInput::class, 'moulding_production_input_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
