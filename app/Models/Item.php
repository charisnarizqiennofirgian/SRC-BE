<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use HasFactory, SoftDeletes;

    const TYPE_RAW_MATERIAL  = 'raw_material';
    const TYPE_CONSUMABLE    = 'consumable';
    const TYPE_COMPONENT     = 'component';
    const TYPE_WIP           = 'wip';
    const TYPE_FINISHED_GOOD = 'finished_good';
    const TYPE_PACKAGING     = 'packaging';
    const TYPE_SPAREPART     = 'sparepart';
    const TYPE_OTHER         = 'other';

    protected $fillable = [
        'name',
        'code',
        'category_id',
        'unit_id',
        'stock',
        'description',
        'type',
        'specifications',
        'nw_per_box',
        'gw_per_box',
        'wood_consumed_per_pcs',
        'm3_per_carton',
        'hs_code',
        'jenis',
        'kualitas',
        'bentuk',
        'volume_m3',
        'production_route',
    ];

    protected $casts = [
        'specifications' => 'array',
    ];

    public static function getTypes(): array
    {
        return [
            self::TYPE_RAW_MATERIAL  => 'Bahan Baku Utama',
            self::TYPE_CONSUMABLE    => 'Bahan Pendukung (Consumable)',
            self::TYPE_COMPONENT     => 'Komponen',
            self::TYPE_WIP           => 'Barang Setengah Jadi (WIP)',
            self::TYPE_FINISHED_GOOD => 'Produk Jadi',
            self::TYPE_PACKAGING     => 'Packaging',
            self::TYPE_SPAREPART     => 'Sparepart',
            self::TYPE_OTHER         => 'Lainnya',
        ];
    }

    public static function getConsumableTypes(): array
    {
        return [
            self::TYPE_CONSUMABLE,
            self::TYPE_SPAREPART,
            self::TYPE_PACKAGING,
        ];
    }

    public function isConsumable(): bool
    {
        return in_array($this->type, self::getConsumableTypes());
    }

    public function isFinishedGood(): bool
    {
        return $this->type === self::TYPE_FINISHED_GOOD;
    }

    public function isRawMaterial(): bool
    {
        return $this->type === self::TYPE_RAW_MATERIAL;
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class, 'item_id');
    }

    public function inventoryLogs()
    {
        return $this->hasMany(InventoryLog::class);
    }

    public function bomComponents()
    {
        return $this->hasMany(ProductBom::class, 'parent_item_id');
    }

    public function bomParents()
    {
        return $this->hasMany(ProductBom::class, 'child_item_id');
    }

    public function scopeConsumables($query)
    {
        return $query->whereIn('type', self::getConsumableTypes());
    }

    public function scopeFinishedGoods($query)
    {
        return $query->where('type', self::TYPE_FINISHED_GOOD);
    }

    public function scopeRawMaterials($query)
    {
        return $query->where('type', self::TYPE_RAW_MATERIAL);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
