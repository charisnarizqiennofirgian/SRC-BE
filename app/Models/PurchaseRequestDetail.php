<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequestDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_request_id',
        'item_id',
        'qty_requested',
        'qty_approved',
        'notes',
    ];

    protected $casts = [
        'qty_requested' => 'float',
        'qty_approved'  => 'float',
    ];

    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
