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
        'subtotal_currency',
        'tax_amount_currency',
        'total_currency',
        'subtotal_idr',
        'tax_amount_idr',
        'total_idr',
        'paid_amount',
        'remaining_amount',
        'payment_status',
        'payment_type',
        'journal_entry_id',
        'notes',
        'status',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'exchange_rate' => 'decimal:2',
        'subtotal_currency' => 'decimal:2',
        'tax_amount_currency' => 'decimal:2',
        'total_currency' => 'decimal:2',
        'subtotal_idr' => 'decimal:2',
        'tax_amount_idr' => 'decimal:2',
        'total_idr' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    protected $with = [
        'buyer',
        'details',
    ];

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function deliveryOrder()
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function details()
    {
        return $this->hasMany(SalesInvoiceDetail::class);
    }

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

    public function isPaid(): bool
    {
        return $this->payment_status === 'PAID';
    }

    public function isPosted(): bool
    {
        return $this->status === 'POSTED';
    }

    public function calculateRemaining(): float
    {
        return floatval($this->total_idr) - floatval($this->paid_amount);
    }
}
