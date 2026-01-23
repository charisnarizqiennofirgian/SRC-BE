<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchasePayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'purchase_bill_id',
        'payment_number',
        'payment_date',
        'amount',
        'payment_method_id',
        'journal_entry_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    /**
     * Relationship ke Purchase Bill
     */
    public function purchaseBill()
    {
        return $this->belongsTo(PurchaseBill::class);
    }

    /**
     * Relationship ke Payment Method (Bank/Kas)
     */
    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * Relationship ke Journal Entry (Jurnal)
     */
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Relationship ke User (yang input)
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Generate nomor pembayaran otomatis
     * Format: PAY-202601-0001
     */
    public static function generatePaymentNumber(): string
    {
        $prefix = 'PAY';
        $year = date('Y');
        $month = date('m');

        $lastPayment = self::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastPayment) {
            $lastNumber = (int) substr($lastPayment->payment_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . '-' . $year . $month . '-' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}
