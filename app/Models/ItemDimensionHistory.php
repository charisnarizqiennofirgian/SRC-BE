<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemDimensionHistory extends Model
{
    protected $fillable = [
        'item_id',
        'changed_by',
        'old_p', 'old_l', 'old_t',
        'new_p', 'new_l', 'new_t',
        'notes',
    ];

    protected $casts = [
        'old_p' => 'float', 'old_l' => 'float', 'old_t' => 'float',
        'new_p' => 'float', 'new_l' => 'float', 'new_t' => 'float',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}