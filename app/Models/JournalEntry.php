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
        // ✅ TAMBAH KOLOM BARU
        'unposted_by',
        'unposted_at',
        'unpost_reason',
        'last_edited_by',
        'last_edited_at',
    ];

    protected $casts = [
        'date' => 'date',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'unposted_at' => 'datetime',      // ✅ TAMBAH
        'last_edited_at' => 'datetime',   // ✅ TAMBAH
    ];

    const STATUS_DRAFT = 'DRAFT';
    const STATUS_POSTED = 'POSTED';
    const STATUS_VOID = 'VOID';

    // EXISTING RELATIONS
    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function details()
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ✅ TAMBAH RELASI BARU
    public function unpostedBy()
    {
        return $this->belongsTo(User::class, 'unposted_by');
    }

    public function lastEditedBy()
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function history()
    {
        return $this->hasMany(JournalHistory::class);
    }

    // EXISTING METHODS
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

    public function isBalanced(): bool
    {
        return $this->total_debit == $this->total_credit;
    }

    // ✅ TAMBAH HELPER METHODS
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function canUnpost(): bool
    {
        return $this->isPosted() && $this->reference_type === 'MANUAL';
    }

    public function canEdit(): bool
    {
        return $this->isDraft();
    }

    public function canDelete(): bool
    {
        return $this->isDraft();
    }

    public function canVoid(): bool
    {
        return $this->isPosted();
    }
}