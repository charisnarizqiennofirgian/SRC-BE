<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'sub_type',
        'currency',
        'is_active',
    ];

    const TYPE_ASET = 'ASET';
    const TYPE_KEWAJIBAN = 'KEWAJIBAN';
    const TYPE_MODAL = 'MODAL';
    const TYPE_PENDAPATAN = 'PENDAPATAN';
    const TYPE_HPP = 'HPP';
    const TYPE_BIAYA = 'BIAYA';

    const SUB_TYPE_AKTIVA_LANCAR         = 'aktiva_lancar';
    const SUB_TYPE_AKTIVA_TETAP          = 'aktiva_tetap';
    const SUB_TYPE_HUTANG_LANCAR         = 'hutang_lancar';
    const SUB_TYPE_HUTANG_JANGKA_PANJANG = 'hutang_jangka_panjang';

    public static function getSubTypes(): array
    {
        return [
            self::SUB_TYPE_AKTIVA_LANCAR         => 'Aktiva Lancar',
            self::SUB_TYPE_AKTIVA_TETAP          => 'Aktiva Tetap',
            self::SUB_TYPE_HUTANG_LANCAR         => 'Hutang Lancar',
            self::SUB_TYPE_HUTANG_JANGKA_PANJANG => 'Hutang Jangka Panjang',
        ];
    }

    public function getAccountNameAttribute()
    {
        return $this->name;
    }

    public static function getTypes(): array
    {
        return [
            self::TYPE_ASET => 'Aset (Harta)',
            self::TYPE_KEWAJIBAN => 'Kewajiban (Hutang)',
            self::TYPE_MODAL => 'Modal (Ekuitas)',
            self::TYPE_PENDAPATAN => 'Pendapatan',
            self::TYPE_HPP => 'HPP (Harga Pokok Penjualan)',
            self::TYPE_BIAYA => 'Biaya (Beban)',
        ];
    }

    public function suppliers()
    {
        return $this->hasMany(Supplier::class, 'payable_account_id');
    }

    public function buyers()
    {
        return $this->hasMany(Buyer::class, 'receivable_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeKewajiban($query)
    {
        return $query->where('type', self::TYPE_KEWAJIBAN);
    }

    public function scopeAset($query)
    {
        return $query->where('type', self::TYPE_ASET);
    }

    public function scopeHpp($query)
    {
        return $query->where('type', self::TYPE_HPP);
    }
}
