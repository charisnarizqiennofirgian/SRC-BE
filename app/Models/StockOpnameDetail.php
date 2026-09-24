<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpnameDetail extends Model
{
    const TYPE_PCS      = 'pcs';
    const TYPE_KAYU     = 'kayu';
    const TYPE_KOMPONEN = 'komponen';

    protected $fillable = [
        'stock_opname_id',
        'item_id',
        'grade',
        'row_type',
        'is_manual',
        'system_qty_pcs',
        'system_qty_natural',
        'system_qty_warna',
        'system_qty_m3',
        'real_qty_pcs',
        'real_qty_natural',
        'real_qty_warna',
        'posted_system_qty_pcs',
        'diff_qty_pcs',
        'diff_qty_m3',
        'notes',
    ];

    protected $casts = [
        'is_manual'             => 'boolean',
        'system_qty_pcs'        => 'float',
        'system_qty_natural'    => 'float',
        'system_qty_warna'      => 'float',
        'system_qty_m3'         => 'float',
        'real_qty_pcs'          => 'float',
        'real_qty_natural'      => 'float',
        'real_qty_warna'        => 'float',
        'posted_system_qty_pcs' => 'float',
        'diff_qty_pcs'          => 'float',
        'diff_qty_m3'           => 'float',
    ];

    public function stockOpname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function isCounted(): bool
    {
        return $this->real_qty_pcs !== null;
    }

    public static function rowTypeForCategory(?string $categoryName): string
    {
        $name = strtolower($categoryName ?? '');
        if (str_contains($name, 'komponen')) {
            return self::TYPE_KOMPONEN;
        }
        if (str_contains($name, 'kayu') || str_contains($name, 'jeblosan')) {
            return self::TYPE_KAYU;
        }

        return self::TYPE_PCS;
    }
}
