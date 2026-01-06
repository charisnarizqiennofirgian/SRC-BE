<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use HasFactory, SoftDeletes;

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
        // field Kayu RST
        'jenis',
        'kualitas',
        'bentuk',
        'volume_m3',
        // field baru untuk jalur produksi
        'production_route',
    ];

    protected $casts = [
        'specifications' => 'array',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    // 🔹 relasi ke tabel stocks (saldo per gudang) - LOGIC LAMA, TETAP ADA
    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    // 🔹 relasi ke tabel inventories (stok per gudang) - BARU untuk Assembling
    public function inventories()
    {
        return $this->hasMany(Inventory::class, 'item_id');
    }

    // 🔹 BOM: item ini sebagai parent (Finished Good) punya banyak child komponen
    public function bomComponents()
    {
        return $this->hasMany(ProductBom::class, 'parent_item_id');
    }

    // 🔹 BOM: item ini sebagai child (komponen) bisa dipakai banyak parent
    public function bomParents()
    {
        return $this->hasMany(ProductBom::class, 'child_item_id');
    }
}