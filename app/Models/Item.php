<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use HasFactory, SoftDeletes;

    const TYPE_RAW_MATERIAL = 'raw_material';
    const TYPE_CONSUMABLE = 'consumable';
    const TYPE_COMPONENT = 'component';
    const TYPE_WIP = 'wip';
    const TYPE_FINISHED_GOOD = 'finished_good';
    const TYPE_PACKAGING = 'packaging';
    const TYPE_SPAREPART = 'sparepart';
    const TYPE_OTHER = 'other';

    const ROUTE_FROM_LOG = 'from_log';
    const ROUTE_FROM_RST = 'from_rst';
    const ROUTE_DIRECT = 'direct';
    const ROUTE_EXTERNAL = 'external';

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
        'jenis_kayu',
        'tpk',
        'diameter',
        'panjang',
        'kubikasi',
        'tanggal_terima',
        'no_skshhk',
        'no_kapling',
        'mutu',
    ];

    protected $casts = [
        'specifications' => 'array',
        'nw_per_box' => 'decimal:2',
        'gw_per_box' => 'decimal:2',
        'wood_consumed_per_pcs' => 'decimal:4',
        'm3_per_carton' => 'decimal:4',
        'volume_m3' => 'decimal:4',
        'diameter' => 'decimal:2',
        'panjang' => 'decimal:2',
        'kubikasi' => 'decimal:4',
    ];

    public static function getTypes(): array
    {
        return [
            self::TYPE_RAW_MATERIAL => 'Bahan Baku Utama',
            self::TYPE_CONSUMABLE => 'Bahan Pendukung (Consumable)',
            self::TYPE_COMPONENT => 'Komponen',
            self::TYPE_WIP => 'Barang Setengah Jadi (WIP)',
            self::TYPE_FINISHED_GOOD => 'Produk Jadi',
            self::TYPE_PACKAGING => 'Packaging',
            self::TYPE_SPAREPART => 'Sparepart',
            self::TYPE_OTHER => 'Lainnya',
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

    public static function getProductionRoutes(): array
    {
        return [
            self::ROUTE_FROM_LOG => 'Dari Log (Lewat Sawmill)',
            self::ROUTE_FROM_RST => 'Dari RST (Skip Sawmill)',
            self::ROUTE_DIRECT => 'Langsung (Tidak butuh sawmill)',
            self::ROUTE_EXTERNAL => 'Beli dari Luar',
        ];
    }

    public function needsSawmill(): bool
    {
        return $this->production_route === self::ROUTE_FROM_LOG;
    }

    public function canUseRst(): bool
    {
        return in_array($this->production_route, [
            self::ROUTE_FROM_RST,
            self::ROUTE_DIRECT,
            null,
        ]);
    }

    public function isExternal(): bool
    {
        return $this->production_route === self::ROUTE_EXTERNAL;
    }

    public function getProductionRouteLabel(): string
    {
        $routes = self::getProductionRoutes();
        return $routes[$this->production_route] ?? 'Belum Ditentukan';
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

    public function scopeNeedsSawmill($query)
    {
        return $query->where('production_route', self::ROUTE_FROM_LOG);
    }

    public function scopeCanUseRst($query)
    {
        return $query->whereIn('production_route', [
            self::ROUTE_FROM_RST,
            self::ROUTE_DIRECT,
        ])->orWhereNull('production_route');
    }

    public function scopeExternal($query)
    {
        return $query->where('production_route', self::ROUTE_EXTERNAL);
    }
}