<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'account_id',
        'is_active',
    ];

    const TYPE_BANK = 'BANK';
    const TYPE_CASH = 'CASH';

    public static function getTypes(): array
    {
        return [
            self::TYPE_BANK => 'Bank',
            self::TYPE_CASH => 'Kas',
        ];
    }

    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBank($query)
    {
        return $query->where('type', self::TYPE_BANK);
    }

    public function scopeCash($query)
    {
        return $query->where('type', self::TYPE_CASH);
    }
}
