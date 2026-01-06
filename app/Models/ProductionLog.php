<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionLog extends Model
{
    protected $fillable = [
        'date',
        'reference_number',
        'process_type',
        'stage',
        'input_item_id',
        'input_quantity',
        'output_item_id',
        'output_quantity',
        'notes',
        'user_id',
    ];

    public function inputItem()
    {
        return $this->belongsTo(Item::class, 'input_item_id');
    }

    public function outputItem()
    {
        return $this->belongsTo(Item::class, 'output_item_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}