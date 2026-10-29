<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'profile_picture_path',
    ];

    protected $appends = [
        'profile_picture_url',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function membershipRequests(): HasMany
    {
        return $this->hasMany(MembershipRequest::class);
    }

    public function organizationProposals(): HasMany
    {
        return $this->hasMany(OrganizationProposal::class);
    }

    public function getProfilePictureUrlAttribute(): ?string
    {
        return $this->profile_picture_path
            ? url(Storage::disk('public')->url($this->profile_picture_path))
            : null;
    }
}
