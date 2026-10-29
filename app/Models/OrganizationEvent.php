<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationEvent extends Model
{
    protected $fillable = [
        'organization_id',
        'title',
        'description',
        'location',
        'starts_at',
        'seat_limit',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'seat_limit' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(OrganizationEventAttendance::class);
    }
}
