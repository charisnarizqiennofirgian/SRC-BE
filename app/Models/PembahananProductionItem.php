<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PembahananProductionItem extends Model
{
    protected $fillable = [
        'pembahanan_production_id',
        'item_id',
        'qty',
    ];

    public function production()
    {
        return $this->belongsTo(PembahananProduction::class, 'pembahanan_production_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}