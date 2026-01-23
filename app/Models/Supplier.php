<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'address',
        'phone',
        'payable_account_id',
    ];

    public function payableAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'payable_account_id');
    }
}
