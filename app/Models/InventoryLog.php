<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class InventoryLog extends Model
{
    protected $fillable = [
        'date',
        'time',
        'item_id',
        'warehouse_id',
        'qty',
        'qty_m3',
        'direction',
        'transaction_type',
        'reference_type',
        'reference_id',
        'reference_number',
        'division',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'date' => 'date',
        'qty' => 'decimal:4',
        'qty_m3' => 'decimal:6',
    ];

    const TYPE_PURCHASE    = 'PURCHASE';
    const TYPE_PRODUCTION  = 'PRODUCTION';
    const TYPE_SALE        = 'SALE';
    const TYPE_USAGE       = 'USAGE';
    const TYPE_ADJUSTMENT  = 'ADJUSTMENT';
    const TYPE_TRANSFER_IN = 'TRANSFER_IN';
    const TYPE_TRANSFER_OUT= 'TRANSFER_OUT';

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function logIn(
        int $itemId,
        int $warehouseId,
        float $qty,
        string $transactionType,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNumber = null,
        ?string $notes = null,
        ?float $qtyM3 = 0
    ): self {
        return self::create([
            'date' => now()->toDateString(),
            'time' => now()->toTimeString(),
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'qty' => $qty,
            'qty_m3' => $qtyM3,
            'direction' => 'IN',
            'transaction_type' => $transactionType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'user_id' => Auth::id(),
        ]);
    }

    public static function logOut(
        int $itemId,
        int $warehouseId,
        float $qty,
        string $transactionType,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNumber = null,
        ?string $division = null,
        ?string $notes = null,
        ?float $qtyM3 = 0
    ): self {
        return self::create([
            'date' => now()->toDateString(),
            'time' => now()->toTimeString(),
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'qty' => $qty,
            'qty_m3' => $qtyM3,
            'direction' => 'OUT',
            'transaction_type' => $transactionType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_number' => $referenceNumber,
            'division' => $division,
            'notes' => $notes,
            'user_id' => Auth::id(),
        ]);
    }

    public static function logUsage(
        int $itemId,
        int $warehouseId,
        float $qty,
        string $division,
        ?string $notes = null
    ): self {
        return self::logOut(
            itemId: $itemId,
            warehouseId: $warehouseId,
            qty: $qty,
            transactionType: self::TYPE_USAGE,
            division: $division,
            notes: $notes ?? "Dipakai divisi {$division}"
        );
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeByWarehouse($query, $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeByItem($query, $itemId)
    {
        return $query->where('item_id', $itemId);
    }

    public function scopeByTransactionType($query, $type)
    {
        return $query->where('transaction_type', $type);
    }

    public function scopeIncoming($query)
    {
        return $query->where('direction', 'IN');
    }

    public function scopeOutgoing($query)
    {
        return $query->where('direction', 'OUT');
    }
}
