<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrderPrint extends Model
{
    public const ACTION_BASELINE = 'baseline';
    public const ACTION_PRINT = 'print';
    public const ACTION_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'sales_order_id',
        'action',
        'content_hash',
        'details_signature',
        'user_id',
    ];

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
