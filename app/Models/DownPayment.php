<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DownPayment extends Model
{
    use HasFactory, SoftDeletes;

    // ✅ TAMBAH: Status Constants
    const STATUS_PENDING = 'PENDING';
    const STATUS_PARTIALLY_USED = 'PARTIALLY_USED';
    const STATUS_FULLY_USED = 'FULLY_USED';

    protected $fillable = [
        'dp_number',
        'sales_order_id',
        'buyer_id',
        'payment_date',
        'currency',
        'exchange_rate',
        'amount_original',
        'amount_idr',
        'account_id',
        'journal_entry_id',
        'status',
        'used_amount',
        'remaining_amount',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'exchange_rate' => 'decimal:4',
        'amount_original' => 'decimal:2',
        'amount_idr' => 'decimal:2',
        'used_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    protected $with = [
        'buyer',
        'account',
    ];

    /**
     * Relationship ke Sales Order
     */
    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * Relationship ke Buyer
     */
    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    /**
     * Relationship ke Chart of Account (COA)
     */
    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    /**
     * Relationship ke Journal Entry
     */
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Relationship ke User (creator)
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relationship ke Invoice Payments yang pakai DP ini
     */
    public function invoicePayments()
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /**
     * Generate nomor DP otomatis
     * Format: DP-202601-0001
     */
    public static function generateDpNumber(): string
    {
        $prefix = 'DP';
        $year = date('Y');
        $month = date('m');

        $lastDp = self::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastDp) {
            $lastNumber = (int) substr($lastDp->dp_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . '-' . $year . $month . '-' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * ✅ UPDATED: Cek apakah DP masih bisa dipakai
     */
    public function isAvailable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PARTIALLY_USED])
            && $this->remaining_amount > 0;
    }

    /**
     * ✅ UPDATED: Update remaining amount & status
     */
    public function updateRemaining(): void
    {
        $this->remaining_amount = $this->amount_idr - $this->used_amount;

        // Update status based on usage
        if ($this->remaining_amount <= 0) {
            $this->status = self::STATUS_FULLY_USED;
        } elseif ($this->used_amount > 0) {
            $this->status = self::STATUS_PARTIALLY_USED;
        } else {
            $this->status = self::STATUS_PENDING;
        }

        $this->save();
    }

    /**
     * ✅ NEW: Get status label for display
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Belum Terpakai',
            self::STATUS_PARTIALLY_USED => 'Sebagian Terpakai',
            self::STATUS_FULLY_USED => 'Sudah Terpakai',
            default => $this->status,
        };
    }

    /**
     * ✅ NEW: Get status badge color for UI
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PARTIALLY_USED => 'info',
            self::STATUS_FULLY_USED => 'success',
            default => 'secondary',
        };
    }
}
