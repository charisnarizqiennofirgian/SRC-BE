<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductBom extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_item_id',
        'child_item_id',
        'qty',
    ];

    public function parentItem()
    {
        return $this->belongsTo(Item::class, 'parent_item_id');
    }

    public function childItem()
    {
        return $this->belongsTo(Item::class, 'child_item_id');
    }
}
