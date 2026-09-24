<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockOpname extends Model
{
    use SoftDeletes;

    const STATUS_DRAFT  = 'draft';
    const STATUS_POSTED = 'posted';

    protected $fillable = [
        'opname_number',
        'warehouse_id',
        'opname_date',
        'status',
        'notes',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected $casts = [
        'opname_date' => 'date:Y-m-d',
        'posted_at'   => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(StockOpnameDetail::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public static function generateNumber($date): string
    {
        $prefix = 'OPN-' . date('Y-m', strtotime($date)) . '-';
        $last = self::withTrashed()
            ->where('opname_number', 'like', $prefix . '%')
            ->orderByDesc('opname_number')
            ->value('opname_number');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }
}
