<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'adjustable_id',
        'adjustable_type',
        'type',
        'quantity',
        'notes',
        'user_id',
    ];

    /**
     * Relasi polimorfik untuk mendapatkan model yang bisa disesuaikan (Produk atau Material).
     */
    public function adjustable()
    {
        return $this->morphTo();
    }

    /**
     * Relasi untuk mendapatkan user yang membuat penyesuaian.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}