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
        'currency',
        'is_active',
    ];

    // Tipe Akun (Bahasa Indonesia)
    const TYPE_ASET = 'ASET';
    const TYPE_KEWAJIBAN = 'KEWAJIBAN';
    const TYPE_MODAL = 'MODAL';
    const TYPE_PENDAPATAN = 'PENDAPATAN';
    const TYPE_BIAYA = 'BIAYA';

    /**
     * Daftar tipe untuk dropdown
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_ASET => 'Aset (Harta)',
            self::TYPE_KEWAJIBAN => 'Kewajiban (Hutang)',
            self::TYPE_MODAL => 'Modal (Ekuitas)',
            self::TYPE_PENDAPATAN => 'Pendapatan',
            self::TYPE_BIAYA => 'Biaya (Beban)',
        ];
    }

    // Relasi ke Suppliers
    public function suppliers()
    {
        return $this->hasMany(Supplier::class, 'payable_account_id');
    }

    // Relasi ke Buyers
    public function buyers()
    {
        return $this->hasMany(Buyer::class, 'receivable_account_id');
    }

    // Scope: Ambil yang aktif saja
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Scope: Filter berdasarkan tipe
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // Scope: Ambil tipe Kewajiban (untuk dropdown Supplier)
    public function scopeKewajiban($query)
    {
        return $query->where('type', self::TYPE_KEWAJIBAN);
    }

    // Scope: Ambil tipe Aset (untuk dropdown Buyer - Piutang)
    public function scopeAset($query)
    {
        return $query->where('type', self::TYPE_ASET);
    }
}
