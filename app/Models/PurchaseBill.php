<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseBill extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supplier_id',
        'bill_number',
        'supplier_invoice_number',
        'bill_date',
        'due_date',
        'subtotal',
        'ppn_amount',
        'total_amount',
        'status',
        'payment_type',
        'payment_method_id',
        'paid_amount',
        'remaining_amount',
        'journal_entry_id',
        'notes',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'ppn_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    // Konstanta Tipe Pembayaran
    const PAYMENT_TEMPO = 'TEMPO';
    const PAYMENT_TUNAI = 'TUNAI';
    const PAYMENT_DP = 'DP';

    public static function getPaymentTypes(): array
    {
        return [
            self::PAYMENT_TEMPO => 'Tempo (Hutang)',
            self::PAYMENT_TUNAI => 'Tunai (Lunas)',
            self::PAYMENT_DP => 'Uang Muka (DP)',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function details()
    {
        return $this->hasMany(PurchaseBillDetail::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Cek apakah sudah lunas
     */
    public function isPaid(): bool
    {
        return $this->remaining_amount <= 0;
    }

    /**
     * Cek apakah masih ada sisa hutang
     */
    public function hasOutstanding(): bool
    {
        return $this->remaining_amount > 0;
    }
}
