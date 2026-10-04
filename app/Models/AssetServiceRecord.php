<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetServiceRecord extends Model
{
    const TYPE_SERVIS_RUTIN = 'servis_rutin';
    const TYPE_PERBAIKAN = 'perbaikan';
    const TYPE_GANTI_SPAREPART = 'ganti_sparepart';
    const TYPE_PAJAK_PERIZINAN = 'pajak_perizinan';
    const TYPE_LAINNYA = 'lainnya';

    protected $fillable = [
        'asset_id',
        'service_date',
        'service_type',
        'description',
        'vendor',
        'cost',
        'meter_reading',
        'next_service_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'service_date' => 'date:Y-m-d',
        'next_service_date' => 'date:Y-m-d',
        'cost' => 'decimal:2',
    ];

    public static function getTypes(): array
    {
        return [
            self::TYPE_SERVIS_RUTIN => 'Servis Rutin',
            self::TYPE_PERBAIKAN => 'Perbaikan',
            self::TYPE_GANTI_SPAREPART => 'Ganti Sparepart',
            self::TYPE_PAJAK_PERIZINAN => 'Pajak / Perizinan',
            self::TYPE_LAINNYA => 'Lainnya',
        ];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
