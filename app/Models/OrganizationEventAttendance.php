<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationEventAttendance extends Model
{
    protected $fillable = [
        'organization_event_id',
        'user_id',
        'rsvp_at',
        'attended_at',
    ];

    protected function casts(): array
    {
        return [
            'rsvp_at' => 'datetime',
            'attended_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(OrganizationEvent::class, 'organization_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
