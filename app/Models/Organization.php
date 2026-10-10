<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = [
        'name',
        'category',
        'college',
        'cause',
        'mission',
        'open_membership',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'open_membership' => 'boolean',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrganizationEvent::class)->orderBy('starts_at');
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MembershipRequest::class);
    }
}
