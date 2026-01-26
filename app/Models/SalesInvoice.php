<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'sales_order_id',
        'delivery_order_id',
        'buyer_id',
        'user_id',
        'invoice_date',
        'due_date',
        'currency',
        'exchange_rate',
        'subtotal_original',
        'discount_original',
        'tax_ppn_original',
        'grand_total_original',
        'subtotal_idr',
        'discount_idr',
        'tax_ppn_idr',
        'grand_total_idr',
        'paid_amount',
        'remaining_amount',
        'payment_status',
        'journal_entry_id',
        'notes',
        'status',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'exchange_rate' => 'decimal:4',
        'subtotal_original' => 'decimal:2',
        'discount_original' => 'decimal:2',
        'tax_ppn_original' => 'decimal:2',
        'grand_total_original' => 'decimal:2',
        'subtotal_idr' => 'decimal:2',
        'discount_idr' => 'decimal:2',
        'tax_ppn_idr' => 'decimal:2',
        'grand_total_idr' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    protected $with = [
        'buyer',
        'details',
    ];

    /**
     * Relationship ke Sales Order
     */
    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * Relationship ke Delivery Order
     */
    public function deliveryOrder()
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    /**
     * Relationship ke Buyer
     */
    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    /**
     * Relationship ke User (yang buat invoice)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship ke Journal Entry
     */
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Relationship ke Invoice Details
     */
    public function details()
    {
        return $this->hasMany(SalesInvoiceDetail::class);
    }

    /**
     * Generate nomor invoice otomatis
     * Format: INV-202601-0001
     */
    public static function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $year = date('Y');
        $month = date('m');

        $lastInvoice = self::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . '-' . $year . $month . '-' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Cek apakah sudah lunas
     */
    public function isPaid(): bool
    {
        return $this->payment_status === 'PAID';
    }

    /**
     * Cek apakah sudah di-posting
     */
    public function isPosted(): bool
    {
        return $this->status === 'POSTED';
    }

    /**
     * Hitung remaining amount
     */
    public function calculateRemaining(): float
    {
        return $this->grand_total_idr - $this->paid_amount;
    }
}
