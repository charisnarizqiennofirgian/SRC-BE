<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'category_id',
        'unit_id',
        'stock',
        'description',
        'type',
        'specifications',
        'nw_per_box',
        'gw_per_box',
        'wood_consumed_per_pcs',
        'm3_per_carton',
        'hs_code',
        // ✅ field Kayu RST
        'jenis',
        'kualitas',
        'bentuk',
    ];

    protected $casts = [
        'specifications' => 'array',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
