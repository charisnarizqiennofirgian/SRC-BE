<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use HasFactory;
    use HasFactory, SoftDeletes;

    // Kolom yang boleh diisi secara massal
    protected $fillable = [
        'name',
        'code',
        'category_id',
        'unit_id',
        'stock',
        'description',
        'type',
    ];

    /**
     * Relasi untuk mengambil data Kategori dari item ini.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Relasi untuk mengambil data Satuan dari item ini.
     */
    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}