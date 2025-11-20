<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BomDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'bom_id',
        'component_item_id',
        'quantity',
        'notes',
    ];

    public function component()
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }

    public function bom()
    {
        return $this->belongsTo(Bom::class, 'bom_id');
    }
}