<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComponentMaterialRecipe extends Model
{
    protected $fillable = [
        'component_item_id',
        'material_item_id',
        'qty_per_unit',
    ];

    public function component()
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }

    public function material()
    {
        return $this->belongsTo(Item::class, 'material_item_id');
    }
}
