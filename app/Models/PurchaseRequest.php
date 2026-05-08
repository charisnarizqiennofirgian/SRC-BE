<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'pr_number',
        'so_id',
        'requested_by',
        'deadline',
        'notes',
        'status',
    ];

    protected $casts = [
        'deadline' => 'date',
    ];

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'so_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function details()
    {
        return $this->hasMany(PurchaseRequestDetail::class);
    }
}
