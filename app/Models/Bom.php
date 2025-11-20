<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bom extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'item_id',
        'code',
        'name',
        'total_wood_m3',
        'description',
    ];

    public function product()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function details()
    {
        return $this->hasMany(BomDetail::class);
    }
}