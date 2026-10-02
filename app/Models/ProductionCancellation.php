<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionCancellation extends Model
{
    protected $fillable = [
        'document_number',
        'stage',
        'reference_type',
        'reference_id',
        'ref_po_id',
        'po_number',
        'document_date',
        'reason',
        'snapshot',
        'cancelled_by',
    ];

    protected $casts = [
        'document_date' => 'date',
        'snapshot'      => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
