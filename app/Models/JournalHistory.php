<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalHistory extends Model
{
    use HasFactory;

    protected $table = 'journal_history';

    // ✅ DISABLE updated_at (table cuma punya created_at)
    const UPDATED_AT = null;

    protected $fillable = [
        'journal_entry_id',
        'action',
        'performed_by',
        'reason',
        'old_data',
        'new_data',
    ];

    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
        'created_at' => 'datetime',
    ];

    // ✅ ACTION CONSTANTS
    const ACTION_CREATED = 'CREATED';
    const ACTION_POSTED = 'POSTED';
    const ACTION_UNPOSTED = 'UNPOSTED';
    const ACTION_EDITED = 'EDITED';
    const ACTION_VOIDED = 'VOIDED';

    // ✅ RELATIONS
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // ✅ HELPER METHOD: Create history log
    public static function log($journalEntryId, $action, $reason = null, $oldData = null, $newData = null)
    {
        return self::create([
            'journal_entry_id' => $journalEntryId,
            'action' => $action,
            'performed_by' => auth()->id(),
            'reason' => $reason,
            'old_data' => $oldData,
            'new_data' => $newData,
        ]);
    }
}