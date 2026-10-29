<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationProposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationProposalController extends Controller
{
    private const COLLEGES = ['CCS', 'CBA', 'CAS', 'CED', 'COE', 'COC', 'All Colleges'];

    private const OFFICER_ROLES = ['President', 'Vice President', 'Treasurer', 'Secretary', 'P.I.O', 'Auditor', 'Officer'];

    public function mine(Request $request)
    {
        return response()->json([
            'data' => $request->user()->organizationProposals()
                ->with(['decisionMaker:id,name', 'organization:id,name'])
                ->latest('updated_at')
                ->get()
                ->map(fn (OrganizationProposal $proposal) => $this->proposalData($proposal))
                ->values(),
        ]);
    }

    public function createDraft(Request $request)
    {
        $this->authorizeOfficer($request);
        $validated = $request->validate($this->proposalRules());
        $this->assertOrganizationNameAvailable($validated['name']);

        $proposal = $request->user()->organizationProposals()->create($validated);

        return response()->json([
            'message' => 'Proposal draft saved.',
            'data' => $this->proposalData($proposal->fresh()),
        ], 201);
    }

    public function updateDraft(Request $request, OrganizationProposal $proposal)
    {
        $this->authorizeOwner($request, $proposal);
        $this->authorizeOfficer($request);
        abort_if($proposal->status !== 'draft', 409, 'Only draft proposals can be edited.');

        $validated = $request->validate($this->proposalRules());
        $this->assertOrganizationNameAvailable($validated['name'], $proposal->id);
        $proposal->update($validated);

        return response()->json([
            'message' => 'Proposal draft updated.',
            'data' => $this->proposalData($proposal->fresh()),
        ]);
    }

    public function submit(Request $request, OrganizationProposal $proposal)
    {
        $this->authorizeOwner($request, $proposal);
        $this->authorizeOfficer($request);
        abort_if($proposal->status !== 'draft', 409, 'Only draft proposals can be submitted.');
        $this->assertOrganizationNameAvailable($proposal->name, $proposal->id);

        $proposal->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Proposal submitted for review.',
            'data' => $this->proposalData($proposal->fresh()),
        ]);
    }

    public function adminIndex(Request $request)
    {
        $this->authorizeReviewer($request);

        $status = $request->input('status', 'submitted');
        abort_unless(in_array($status, ['submitted', 'all'], true), 422, 'Invalid proposal status filter.');

        $proposals = OrganizationProposal::query()
            ->with(['proposer:id,name,email', 'decisionMaker:id,name', 'organization:id,name'])
            ->when(
                $status === 'all',
                fn (Builder $query) => $query->whereIn('status', ['submitted', 'approved', 'declined']),
                fn (Builder $query) => $query->where('status', $status),
            )
            ->orderByRaw("CASE status WHEN 'submitted' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")
            ->latest('submitted_at')
            ->get()
            ->map(fn (OrganizationProposal $proposal) => $this->proposalData($proposal, true))
            ->values();

        return response()->json(['data' => $proposals]);
    }

    public function decide(Request $request, OrganizationProposal $proposal)
    {
        $this->authorizeReviewer($request);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'declined'])],
            'decision_note' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_if($proposal->status !== 'submitted', 409, 'Only submitted proposals can be decided.');

        $proposal = DB::transaction(function () use ($request, $proposal, $validated) {
            $proposal = OrganizationProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            abort_if($proposal->status !== 'submitted', 409, 'This proposal has already been decided.');

            $organizationId = null;
            if ($validated['status'] === 'approved') {
                if (Organization::query()->where('name', $proposal->name)->exists()) {
                    throw ValidationException::withMessages([
                        'name' => ['An organization with this name already exists.'],
                    ]);
                }

                $organization = Organization::create([
                    'name' => $proposal->name,
                    'category' => $proposal->category,
                    'college' => $proposal->college,
                    'cause' => $proposal->cause,
                    'mission' => $proposal->mission,
                    'open_membership' => true,
                ]);
                OrganizationMember::create([
                    'organization_id' => $organization->id,
                    'user_id' => $proposal->user_id,
                    'role' => 'President',
                ]);
                $organizationId = $organization->id;
            }

            $proposal->update([
                'status' => $validated['status'],
                'decision_note' => $validated['decision_note'] ?? null,
                'decided_by' => $request->user()->id,
                'organization_id' => $organizationId,
                'decided_at' => now(),
            ]);

            return $proposal->fresh()->load([
                'proposer:id,name,email',
                'decisionMaker:id,name',
                'organization:id,name',
            ]);
        });

        return response()->json([
            'message' => 'Proposal '.$validated['status'].'.',
            'data' => $this->proposalData($proposal, true),
        ]);
    }

    private function proposalRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:100'],
            'college' => ['required', Rule::in(self::COLLEGES)],
            'cause' => ['required', 'string', 'max:255'],
            'mission' => ['required', 'string', 'max:5000'],
        ];
    }

    private function assertOrganizationNameAvailable(string $name, ?int $exceptProposalId = null): void
    {
        abort_if(
            Organization::query()->where('name', $name)->exists(),
            409,
            'An organization with this name already exists.',
        );
        abort_if(
            OrganizationProposal::query()
                ->where('name', $name)
                ->where('status', 'submitted')
                ->when($exceptProposalId, fn (Builder $query) => $query->where('id', '!=', $exceptProposalId))
                ->exists(),
            409,
            'A proposal with this name is already being reviewed.',
        );
    }

    private function authorizeOwner(Request $request, OrganizationProposal $proposal): void
    {
        abort_unless($proposal->user_id === $request->user()->id, 403, 'You can only manage your own organization proposals.');
    }

    private function authorizeOfficer(Request $request): void
    {
        abort_unless(
            $request->user()->organizationMemberships()->whereIn('role', self::OFFICER_ROLES)->exists(),
            403,
            'Only organization officers can create, edit, or submit proposals.',
        );
    }

    private function authorizeReviewer(Request $request): void
    {
        abort_unless(
            $request->user()->is_admin
                || $request->user()->organizationMemberships()->where('role', 'President')->exists(),
            403,
            'Only administrators or organization presidents can review proposals.',
        );
    }

    private function proposalData(OrganizationProposal $proposal, bool $includeProposer = false): array
    {
        return [
            'id' => $proposal->id,
            'name' => $proposal->name,
            'category' => $proposal->category,
            'college' => $proposal->college,
            'cause' => $proposal->cause,
            'mission' => $proposal->mission,
            'status' => $proposal->status,
            'decision_note' => $proposal->decision_note,
            'submitted_at' => $proposal->submitted_at,
            'decided_at' => $proposal->decided_at,
            'organization' => $proposal->organization,
            'decision_maker' => $proposal->decisionMaker,
            ...($includeProposer ? ['proposer' => $proposal->proposer] : []),
        ];
    }
}
