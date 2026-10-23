<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MembershipRequest;
use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationEvent;
use App\Models\OrganizationEventAttendance;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    private const OFFICER_ROLES = ['President', 'Vice President', 'Treasurer', 'Officer'];

    private const COLLEGES = [
        ['code' => 'CCS', 'name' => 'College of Computer Studies'],
        ['code' => 'CBA', 'name' => 'College of Business Administration'],
        ['code' => 'CAS', 'name' => 'College of Arts and Sciences'],
        ['code' => 'CED', 'name' => 'College of Education'],
        ['code' => 'COE', 'name' => 'College of Engineering'],
        ['code' => 'COC', 'name' => 'College of Criminology'],
    ];

    public function index(Request $request)
    {
        $organizations = Organization::query()
            ->withCount('members')
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(function (Builder $query) use ($term) {
                    $query->where('name', 'like', $term)
                        ->orWhere('cause', 'like', $term);
                });
            })
            ->when($request->filled('college') && $request->input('college') !== 'All Colleges', function (Builder $query) use ($request) {
                $query->where(function (Builder $query) use ($request) {
                    $query->where('college', $request->input('college'))
                        ->orWhere('college', 'All Colleges');
                });
            })
            ->when($request->filled('type') && $request->input('type') !== 'All Types', fn (Builder $query) => $query->where('category', $request->input('type')))
            ->when($request->boolean('open'), fn (Builder $query) => $query->where('open_membership', true))
            ->when($request->user(), fn (Builder $query) => $query->with(['requests' => fn ($requests) => $requests->where('user_id', $request->user()->id)]))
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $organization) => $this->summary($organization, $request->user()?->id));

        return response()->json([
            'data' => $organizations,
            'meta' => ['colleges' => self::COLLEGES],
        ]);
    }

    public function show(Request $request, Organization $organization)
    {
        $membership = $request->user()
            ? $organization->members()->where('user_id', $request->user()->id)->first()
            : null;
        $canViewOrganizationTools = $membership !== null || $request->user()?->is_admin;

        $organization->load([
            'events' => fn ($events) => $events
                ->withCount([
                    'attendances as rsvp_count' => fn ($query) => $query->whereNotNull('rsvp_at'),
                    'attendances as attendance_count' => fn ($query) => $query->whereNotNull('attended_at'),
                ])
                ->with(['attendances' => fn ($query) => $membership
                    ? $query->where('user_id', $membership->user_id)
                    : $query->whereRaw('1 = 0')]),
            'announcements' => fn ($announcements) => $canViewOrganizationTools
                ? $announcements->with(['author:id,name,profile_picture_path', 'organization:id,name,college'])
                : $announcements->whereRaw('1 = 0'),
            'members' => fn ($members) => $members
                ->whereIn('role', self::OFFICER_ROLES)
                ->with('user:id,name,profile_picture_path'),
        ])->loadCount('members');

        $membershipRequest = $request->user()
            ? $organization->requests()->where('user_id', $request->user()->id)->first()
            : null;

        return response()->json([
            'data' => [
                ...$this->summary($organization, $request->user()?->id),
                'mission' => $organization->mission,
                'officers' => $organization->members->map(fn (OrganizationMember $member) => [
                    'name' => $member->user->name,
                    'role' => $member->role,
                    'profile_picture_url' => $member->user->profile_picture_url,
                ])->values(),
                'events' => $organization->events->map(fn (OrganizationEvent $event) => $this->eventData($event))->values(),
                'announcements' => $canViewOrganizationTools
                    ? $organization->announcements->map(fn (OrganizationAnnouncement $announcement) => $this->announcementData($announcement))->values()
                    : [],
                'membership_role' => $membership?->role,
                'request_status' => $membershipRequest?->status,
            ],
        ]);
    }

    public function requestToJoin(Request $request, Organization $organization)
    {
        abort_unless($organization->open_membership, 403, 'This organization is not currently accepting members.');
        abort_if($organization->members()->where('user_id', $request->user()->id)->exists(), 409, 'You are already a member of this organization.');

        $membershipRequest = DB::transaction(function () use ($request, $organization) {
            $membershipRequest = MembershipRequest::firstOrNew([
                'organization_id' => $organization->id,
                'user_id' => $request->user()->id,
            ]);

            if ($membershipRequest->exists && $membershipRequest->status === 'pending') {
                abort(409, 'Your request is already pending.');
            }

            $membershipRequest->fill([
                'status' => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();

            return $membershipRequest;
        });

        return response()->json([
            'message' => 'Your request to join has been sent.',
            'data' => $membershipRequest->load('organization'),
        ], 201);
    }

    public function withdraw(Request $request, Organization $organization)
    {
        $membershipRequest = $organization->requests()
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->firstOrFail();

        $membershipRequest->update([
            'status' => 'withdrawn',
            'reviewed_by' => null,
            'reviewed_at' => now(),
        ]);

        return response()->json(['message' => 'Your pending request has been withdrawn.']);
    }

    public function myRequests(Request $request)
    {
        return response()->json([
            'data' => $request->user()->membershipRequests()
                ->with('organization')
                ->latest('updated_at')
                ->get(),
        ]);
    }

    public function myOrganizations(Request $request)
    {
        if ($request->user()->is_admin) {
            return response()->json([
                'data' => Organization::query()->orderBy('name')->get()
                    ->map(fn (Organization $organization) => [
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'category' => $organization->category,
                        'college' => $organization->college,
                        'role' => $request->user()->organizationMemberships()
                            ->where('organization_id', $organization->id)
                            ->value('role') ?? 'Admin',
                    ]),
            ]);
        }

        return response()->json([
            'data' => $request->user()->organizationMemberships()
                ->with('organization')
                ->orderBy('role')
                ->get()
                ->map(fn (OrganizationMember $membership) => [
                    'id' => $membership->organization->id,
                    'name' => $membership->organization->name,
                    'category' => $membership->organization->category,
                    'college' => $membership->organization->college,
                    'role' => $membership->role,
                ]),
        ]);
    }

    public function officerRequests(Request $request)
    {
        $organizationIds = $request->user()->is_admin
            ? Organization::query()->select('id')
            : $request->user()->organizationMemberships()->where('role', 'President')->select('organization_id');

        $requests = MembershipRequest::query()
            ->whereIn('organization_id', $organizationIds)
            ->with(['user:id,name,email,profile_picture_path', 'organization:id,name'])
            ->when($request->filled('organization_id'), fn (Builder $query) => $query->where('organization_id', $request->integer('organization_id')))
            ->when($request->input('status', 'pending') !== 'all', fn (Builder $query) => $query->where('status', $request->input('status', 'pending')))
            ->latest()
            ->get();

        return response()->json(['data' => $requests]);
    }

    public function decideRequest(Request $request, MembershipRequest $membershipRequest)
    {
        abort_unless($request->user()->is_admin || $request->user()->organizationMemberships()
                ->where('organization_id', $membershipRequest->organization_id)
                ->where('role', 'President')
                ->exists(),
            403,
            'Only the organization president can review join requests.',
        );
        abort_if($membershipRequest->status !== 'pending', 409, 'This request has already been reviewed.');

        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'declined'])],
        ]);

        DB::transaction(function () use ($request, $membershipRequest, $validated) {
            $membershipRequest->update([
                'status' => $validated['status'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            if ($validated['status'] === 'approved') {
                OrganizationMember::updateOrCreate(
                    [
                        'organization_id' => $membershipRequest->organization_id,
                        'user_id' => $membershipRequest->user_id,
                    ],
                    ['role' => 'Member', 'joined_at' => now()],
                );
            }
        });

        return response()->json([
            'message' => 'Request '.$validated['status'].'.',
            'data' => $membershipRequest->fresh()->load(['user:id,name,email', 'organization:id,name']),
        ]);
    }

    public function members(Request $request, Organization $organization)
    {
        abort_unless(
            $request->user()->is_admin || $organization->members()->where('user_id', $request->user()->id)->exists(),
            403,
            'Only organization members can view the member list.',
        );

        return response()->json([
            'data' => $organization->members()
                ->with('user:id,name,email,profile_picture_path')
                ->orderByRaw("CASE role WHEN 'President' THEN 0 WHEN 'Vice President' THEN 1 WHEN 'Treasurer' THEN 2 WHEN 'Officer' THEN 3 ELSE 4 END")
                ->orderBy('joined_at')
                ->get()
                ->map(fn (OrganizationMember $membership) => [
                    'id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'profile_picture_url' => $membership->user->profile_picture_url,
                    'role' => $membership->role,
                    'joined_at' => $membership->joined_at,
                ]),
        ]);
    }

    public function removeMember(Request $request, Organization $organization, OrganizationMember $member)
    {
        $this->authorizePresident($request, $organization);
        abort_unless($member->organization_id === $organization->id, 404);
        abort_if($member->user_id === $request->user()->id, 409, 'You cannot remove yourself from the organization.');
        abort_if($member->role === 'President', 409, 'The organization president cannot be removed.');

        $member->delete();

        return response()->json(['message' => 'Member removed from the organization.']);
    }

    public function promoteMember(Request $request, Organization $organization, OrganizationMember $member)
    {
        $this->authorizePresident($request, $organization);
        abort_unless($member->organization_id === $organization->id, 404);
        abort_if($member->role !== 'Member', 409, 'Only regular members can be promoted to officer.');

        $member->load('user:id,name,email,profile_picture_path');
        $member->update(['role' => 'Officer']);

        return response()->json([
            'message' => 'Member promoted to officer.',
            'data' => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'profile_picture_url' => $member->user->profile_picture_url,
                'role' => $member->role,
                'joined_at' => $member->joined_at,
            ],
        ]);
    }

    public function announcements(Request $request)
    {
        $organizationIds = $request->user()->is_admin
            ? Organization::query()->select('id')
            : $request->user()->organizationMemberships()->select('organization_id');

        return response()->json([
            'data' => OrganizationAnnouncement::query()
                ->whereIn('organization_id', $organizationIds)
                ->with(['organization:id,name,college', 'author:id,name,profile_picture_path'])
                ->latest()
                ->get()
                ->map(fn (OrganizationAnnouncement $announcement) => $this->announcementData($announcement)),
        ]);
    }

    public function postAnnouncement(Request $request, Organization $organization)
    {
        $this->authorizePresident($request, $organization);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $announcement = $organization->announcements()->create([
            ...$validated,
            'user_id' => $request->user()->id,
        ])->load(['organization:id,name,college', 'author:id,name,profile_picture_path']);

        return response()->json([
            'message' => 'Announcement posted.',
            'data' => $this->announcementData($announcement),
        ], 201);
    }

    public function deleteAnnouncement(Request $request, Organization $organization, OrganizationAnnouncement $announcement)
    {
        $this->authorizePresident($request, $organization);
        abort_unless($announcement->organization_id === $organization->id, 404);

        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }

    public function myEvents(Request $request)
    {
        $organizationIds = $request->user()->is_admin
            ? Organization::query()->select('id')
            : $request->user()->organizationMemberships()->select('organization_id');

        return response()->json([
            'data' => OrganizationEvent::query()
                ->whereIn('organization_id', $organizationIds)
                ->with('organization:id,name,college')
                ->withCount([
                    'attendances as rsvp_count' => fn ($query) => $query->whereNotNull('rsvp_at'),
                    'attendances as attendance_count' => fn ($query) => $query->whereNotNull('attended_at'),
                ])
                ->with(['attendances' => fn ($query) => $query->where('user_id', $request->user()->id)])
                ->orderBy('starts_at')
                ->get()
                ->map(fn (OrganizationEvent $event) => $this->eventData($event)),
        ]);
    }

    public function rsvpToEvent(Request $request, Organization $organization, OrganizationEvent $event)
    {
        $this->assertEventBelongsToOrganization($event, $organization);
        abort_unless(
            $organization->members()->where('user_id', $request->user()->id)->exists(),
            403,
            'Only organization members can RSVP to its events.',
        );
        abort_if($event->starts_at->isPast(), 409, 'You cannot RSVP to an event that has already started.');

        $attendance = OrganizationEventAttendance::firstOrNew([
            'organization_event_id' => $event->id,
            'user_id' => $request->user()->id,
        ]);
        $attendance->rsvp_at ??= now();
        $attendance->save();

        return response()->json([
            'message' => 'You are RSVP’d for this event.',
            'data' => ['event_id' => $event->id, 'rsvped' => true],
        ]);
    }

    public function cancelEventRsvp(Request $request, Organization $organization, OrganizationEvent $event)
    {
        $this->assertEventBelongsToOrganization($event, $organization);
        abort_unless(
            $organization->members()->where('user_id', $request->user()->id)->exists(),
            403,
            'Only organization members can manage their RSVP.',
        );

        OrganizationEventAttendance::query()
            ->where('organization_event_id', $event->id)
            ->where('user_id', $request->user()->id)
            ->whereNotNull('rsvp_at')
            ->update(['rsvp_at' => null, 'updated_at' => now()]);

        return response()->json([
            'message' => 'Your RSVP has been cancelled.',
            'data' => ['event_id' => $event->id, 'rsvped' => false],
        ]);
    }

    public function deleteEvent(Request $request, Organization $organization, OrganizationEvent $event)
    {
        $this->authorizePresident($request, $organization);
        $this->assertEventBelongsToOrganization($event, $organization);

        $event->delete();

        return response()->json(['message' => 'Event deleted.']);
    }

    public function officerEvents(Request $request, Organization $organization)
    {
        $this->authorizePresident($request, $organization);

        return response()->json([
            'data' => $organization->events()
                ->withCount([
                    'attendances as rsvp_count' => fn ($query) => $query->whereNotNull('rsvp_at'),
                    'attendances as attendance_count' => fn ($query) => $query->whereNotNull('attended_at'),
                ])
                ->orderBy('starts_at')
                ->get()
                ->map(fn (OrganizationEvent $event) => $this->eventData($event)),
        ]);
    }

    public function eventAttendance(Request $request, OrganizationEvent $event)
    {
        $organization = $event->organization;
        $this->authorizePresident($request, $organization);

        $members = $organization->members()->with('user:id,name,email,profile_picture_path')->get();
        $attendance = $event->attendances()
            ->whereIn('user_id', $members->pluck('user_id'))
            ->get()
            ->keyBy('user_id');

        return response()->json([
            'data' => $members->map(fn (OrganizationMember $membership) => [
                'user_id' => $membership->user_id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'profile_picture_url' => $membership->user->profile_picture_url,
                'role' => $membership->role,
                'rsvped' => $attendance->get($membership->user_id)?->rsvp_at !== null,
                'attended' => $attendance->get($membership->user_id)?->attended_at !== null,
            ])->values(),
        ]);
    }

    public function markEventAttendance(Request $request, OrganizationEvent $event, User $user)
    {
        $organization = $event->organization;
        $this->authorizePresident($request, $organization);
        abort_unless(
            $organization->members()->where('user_id', $user->id)->exists(),
            404,
            'This user is not a member of the organization.',
        );

        $attendance = OrganizationEventAttendance::firstOrNew([
            'organization_event_id' => $event->id,
            'user_id' => $user->id,
        ]);
        $attendance->attended_at ??= now();
        $attendance->save();

        return response()->json([
            'message' => 'Attendance marked.',
            'data' => ['event_id' => $event->id, 'user_id' => $user->id, 'attended' => true],
        ]);
    }

    private function assertEventBelongsToOrganization(OrganizationEvent $event, Organization $organization): void
    {
        abort_unless($event->organization_id === $organization->id, 404);
    }

    private function eventData(OrganizationEvent $event): array
    {
        return [
            'id' => $event->id,
            'organization_id' => $event->organization_id,
            'title' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'starts_at' => $event->starts_at,
            'is_past' => $event->starts_at->isPast(),
            'rsvp_count' => (int) ($event->rsvp_count ?? 0),
            'attendance_count' => (int) ($event->attendance_count ?? 0),
            'rsvped' => $event->relationLoaded('attendances')
                && $event->attendances->first()?->rsvp_at !== null,
            'organization' => $event->relationLoaded('organization') && $event->organization ? [
                'id' => $event->organization->id,
                'name' => $event->organization->name,
                'college' => $event->organization->college,
            ] : null,
        ];
    }

    private function authorizePresident(Request $request, Organization $organization): void
    {
        abort_unless(
            $request->user()->is_admin || $request->user()->organizationMemberships()
                ->where('organization_id', $organization->id)
                ->where('role', 'President')
                ->exists(),
            403,
            'Only the organization president can manage organization tools.',
        );
    }

    private function announcementData(OrganizationAnnouncement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'created_at' => $announcement->created_at,
            'organization' => [
                'id' => $announcement->organization->id,
                'name' => $announcement->organization->name,
                'college' => $announcement->organization->college,
            ],
            'author' => [
                'id' => $announcement->author->id,
                'name' => $announcement->author->name,
                'profile_picture_url' => $announcement->author->profile_picture_url,
            ],
        ];
    }

    private function summary(Organization $organization, ?int $userId): array
    {
        $requestStatus = null;
        if ($userId !== null) {
            $requestStatus = $organization->relationLoaded('requests')
                ? $organization->requests->firstWhere('user_id', $userId)?->status
                : $organization->requests()->where('user_id', $userId)->value('status');
        }

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'category' => $organization->category,
            'college' => $organization->college,
            'cause' => $organization->cause,
            'open' => $organization->open_membership,
            'color' => $organization->color,
            'members' => $organization->members_count,
            'request_status' => $requestStatus,
        ];
    }
}
