<?php

namespace App\Models; 

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'material_category_id',
        'unit_id',
        'stock',
        
    ];

    /**
     * Get the category for the material.
     */
    public function materialCategory()
    {
        return $this->belongsTo(MaterialCategory::class);
    }

    /**
     * Get the unit for the material.
     */
    public function unit()
    {
        // Pastikan nama model ini juga benar (Unit)
        return $this->belongsTo(Unit::class);
    }
}