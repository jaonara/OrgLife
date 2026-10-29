<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationProposal extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'category',
        'college',
        'cause',
        'mission',
        'status',
        'decision_note',
        'decided_by',
        'organization_id',
        'submitted_at',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
