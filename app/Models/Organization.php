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
        'gcash_number',
        'gcash_qr_path',
    ];

    protected function casts(): array
    {
        return [
            'open_membership' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->whereNull($this->qualifyColumn('archived_at'));
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrganizationEvent::class)->orderBy('starts_at');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(OrganizationAnnouncement::class)->latest();
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MembershipRequest::class);
    }

    public function dues(): HasMany
    {
        return $this->hasMany(OrganizationDue::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(OrganizationReport::class);
    }
}
