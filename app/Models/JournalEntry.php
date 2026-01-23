<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_number',
        'date',
        'description',
        'reference_type',
        'reference_id',
        'total_debit',
        'total_credit',
        'status',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
    ];

    const STATUS_DRAFT = 'DRAFT';
    const STATUS_POSTED = 'POSTED';
    const STATUS_VOID = 'VOID';

    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Generate nomor jurnal otomatis
     */
    public static function generateJournalNumber(): string
    {
        $prefix = 'JRN';
        $year = date('Y');
        $month = date('m');

        $lastJournal = self::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastJournal) {
            $lastNumber = (int) substr($lastJournal->journal_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . $year . $month . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Cek apakah jurnal balance (debit = kredit)
     */
    public function isBalanced(): bool
    {
        return $this->total_debit == $this->total_credit;
    }
}