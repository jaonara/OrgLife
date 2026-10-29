<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuesPayment extends Model
{
    protected $fillable = [
        'organization_due_id',
        'user_id',
        'recorded_by',
        'amount',
        'paid_at',
        'status',
        'reference_last_four',
        'proof_path',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function organizationDue(): BelongsTo
    {
        return $this->belongsTo(OrganizationDue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
