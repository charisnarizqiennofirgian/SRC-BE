<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QcFinalPassedItem extends Model
{
    protected $fillable = [
        'qc_final_production_id',
        'item_id',
        'qty',
    ];

    public function production()
    {
        return $this->belongsTo(QcFinalProduction::class, 'qc_final_production_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}