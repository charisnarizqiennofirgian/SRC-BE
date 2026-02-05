<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductionOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'po_number',
        'sales_order_id',
        'status',
        'current_stage',
        'skip_sawmill',
        'notes',
        'created_by',
    ];

    // ========================================
    // CONSTANTS - STAGES
    // ========================================
    const STAGE_PENDING = 'pending';
    const STAGE_SAWMILL = 'sawmill';
    const STAGE_PEMBAHANAN = 'pembahanan';
    const STAGE_MOULDING = 'moulding';
    const STAGE_ASSEMBLY = 'assembly';
    const STAGE_FINISHING = 'finishing';
    const STAGE_PACKING = 'packing';
    const STAGE_COMPLETED = 'completed';

    // ========================================
    // CONSTANTS - STATUS
    // ========================================
    const STATUS_DRAFT = 'draft';
    const STATUS_RELEASED = 'released';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Get all available stages
     */
    public static function getStages(): array
    {
        return [
            self::STAGE_PENDING => 'Pending',
            self::STAGE_SAWMILL => 'Sawmill',
            self::STAGE_PEMBAHANAN => 'Pembahanan (RST)',
            self::STAGE_MOULDING => 'Moulding',
            self::STAGE_ASSEMBLY => 'Assembly',
            self::STAGE_FINISHING => 'Finishing',
            self::STAGE_PACKING => 'Packing',
            self::STAGE_COMPLETED => 'Completed',
        ];
    }

    /**
     * Get all available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_RELEASED => 'Released',
            self::STATUS_IN_PROGRESS => 'In Progress',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /**
     * Check if PO is at specific stage
     */
    public function isAtStage(string $stage): bool
    {
        return $this->current_stage === $stage;
    }

    /**
     * Check if PO can skip sawmill
     */
    public function canSkipSawmill(): bool
    {
        return $this->skip_sawmill === true || $this->skip_sawmill === 1;
    }

    /**
     * Move to next stage
     */
    public function moveToNextStage(): string
    {
        $stageOrder = [
            self::STAGE_PENDING => self::STAGE_SAWMILL,
            self::STAGE_SAWMILL => self::STAGE_PEMBAHANAN,
            self::STAGE_PEMBAHANAN => self::STAGE_MOULDING,
            self::STAGE_MOULDING => self::STAGE_ASSEMBLY,
            self::STAGE_ASSEMBLY => self::STAGE_FINISHING,
            self::STAGE_FINISHING => self::STAGE_PACKING,
            self::STAGE_PACKING => self::STAGE_COMPLETED,
        ];

        // If skip sawmill and still pending, go directly to pembahanan
        if ($this->canSkipSawmill() && $this->current_stage === self::STAGE_PENDING) {
            return self::STAGE_PEMBAHANAN;
        }

        return $stageOrder[$this->current_stage] ?? self::STAGE_COMPLETED;
    }

    // ========================================
    // RELATIONSHIPS
    // ========================================

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function details()
    {
        return $this->hasMany(ProductionOrderDetail::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ========================================
    // SCOPES
    // ========================================

    /**
     * Scope: Filter by stage
     */
    public function scopeAtStage($query, string $stage)
    {
        return $query->where('current_stage', $stage);
    }

    /**
     * Scope: Filter by status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Only released POs
     */
    public function scopeReleased($query)
    {
        return $query->where('status', self::STATUS_RELEASED)
                     ->orWhere('status', self::STATUS_IN_PROGRESS);
    }

    /**
     * Scope: POs that can skip sawmill
     */
    public function scopeSkipSawmill($query)
    {
        return $query->where('skip_sawmill', true);
    }

    /**
     * Scope: POs that need sawmill
     */
    public function scopeNeedSawmill($query)
    {
        return $query->where('skip_sawmill', false)
                     ->orWhereNull('skip_sawmill');
    }
}
