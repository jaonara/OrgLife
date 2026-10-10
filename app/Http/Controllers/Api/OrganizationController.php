<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MembershipRequest;
use App\Models\Organization;
use App\Models\OrganizationMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
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
        $organization->load([
            'events',
            'members' => fn ($members) => $members
                ->whereIn('role', ['President', 'Officer'])
                ->with('user:id,name'),
        ])->loadCount('members');

        $membership = $request->user()
            ? $organization->members()->where('user_id', $request->user()->id)->first()
            : null;
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
                ])->values(),
                'events' => $organization->events,
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
        $organizationIds = $request->user()->organizationMemberships()
            ->whereIn('role', ['President', 'Officer'])
            ->select('organization_id');

        $requests = MembershipRequest::query()
            ->whereIn('organization_id', $organizationIds)
            ->with(['user:id,name,email', 'organization:id,name'])
            ->when($request->filled('organization_id'), fn (Builder $query) => $query->where('organization_id', $request->integer('organization_id')))
            ->when($request->input('status', 'pending') !== 'all', fn (Builder $query) => $query->where('status', $request->input('status', 'pending')))
            ->latest()
            ->get();

        return response()->json(['data' => $requests]);
    }

    public function decideRequest(Request $request, MembershipRequest $membershipRequest)
    {
        abort_unless(
            $request->user()->organizationMemberships()
                ->where('organization_id', $membershipRequest->organization_id)
                ->whereIn('role', ['President', 'Officer'])
                ->exists(),
            403,
            'Only organization officers can review join requests.',
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
            $organization->members()->where('user_id', $request->user()->id)->exists(),
            403,
            'Only organization members can view the member list.',
        );

        return response()->json([
            'data' => $organization->members()
                ->with('user:id,name,email')
                ->orderByRaw("CASE role WHEN 'President' THEN 0 WHEN 'Officer' THEN 1 ELSE 2 END")
                ->orderBy('joined_at')
                ->get()
                ->map(fn (OrganizationMember $membership) => [
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => $membership->role,
                    'joined_at' => $membership->joined_at,
                ]),
        ]);
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
