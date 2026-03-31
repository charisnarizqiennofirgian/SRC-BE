<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RustikKomponenOutput extends Model
{
    protected $fillable = [
        'rustik_komponen_production_id',
        'item_id',
        'qty',
    ];

    public function production()
    {
        return $this->belongsTo(RustikKomponenProduction::class, 'rustik_komponen_production_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}