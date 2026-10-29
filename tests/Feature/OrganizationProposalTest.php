<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_proposer_can_save_draft_submit_and_track_admin_decision(): void
    {
        $proposer = User::factory()->create();
        $this->addMembership($proposer, 'Secretary');
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $payload = $this->proposalPayload();

        Sanctum::actingAs($proposer);
        $draft = $this->postJson('/api/organization-proposals', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->getJson('/api/my-organization-proposals')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'draft');

        $this->putJson("/api/organization-proposals/{$draft}", [
            ...$payload,
            'cause' => 'Updated community cause',
        ])
            ->assertOk()
            ->assertJsonPath('data.cause', 'Updated community cause');

        $this->patchJson("/api/organization-proposals/{$draft}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
        $this->putJson("/api/organization-proposals/{$draft}", $payload)
            ->assertConflict();

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/organization-proposals')
            ->assertOk()
            ->assertJsonPath('data.0.proposer.id', $proposer->id)
            ->assertJsonPath('data.0.status', 'submitted');
        $this->patchJson("/api/admin/organization-proposals/{$draft}", [
            'status' => 'approved',
            'decision_note' => 'Approved for campus launch.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.organization.name', $payload['name']);

        $organization = Organization::where('name', $payload['name'])->firstOrFail();
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $proposer->id,
            'role' => 'President',
        ]);
        $this->assertDatabaseHas('organization_proposals', [
            'id' => $draft,
            'status' => 'approved',
            'organization_id' => $organization->id,
            'decision_note' => 'Approved for campus launch.',
        ]);

        Sanctum::actingAs($proposer);
        $this->getJson('/api/my-organization-proposals')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('data.0.organization.name', $payload['name']);
    }

    public function test_only_admins_and_presidents_can_review_submitted_proposals_and_declines_are_tracked(): void
    {
        $proposer = User::factory()->create();
        $otherUser = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $proposal = OrganizationProposal::create([
            'user_id' => $proposer->id,
            ...$this->proposalPayload(),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($otherUser);
        $this->getJson('/api/admin/organization-proposals')->assertForbidden();
        $this->patchJson("/api/admin/organization-proposals/{$proposal->id}", ['status' => 'declined'])
            ->assertForbidden();
        $this->getJson('/api/my-organization-proposals')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/organization-proposals/{$proposal->id}", [
            'status' => 'declined',
            'decision_note' => 'Please provide a more detailed plan.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.decision_note', 'Please provide a more detailed plan.');
        $this->assertDatabaseMissing('organizations', ['name' => $proposal->name]);

        $this->patchJson("/api/admin/organization-proposals/{$proposal->id}", ['status' => 'approved'])
            ->assertConflict();
    }

    public function test_presidents_can_review_and_decide_proposals_but_other_officers_cannot(): void
    {
        $president = User::factory()->create();
        $this->addMembership($president, 'President');
        $officer = User::factory()->create();
        $this->addMembership($officer, 'Treasurer');
        $proposal = OrganizationProposal::create([
            'user_id' => User::factory()->create()->id,
            ...$this->proposalPayload(),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $draft = OrganizationProposal::create([
            'user_id' => User::factory()->create()->id,
            ...$this->proposalPayload(),
            'name' => 'Unsubmitted Draft Proposal',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($officer);
        $this->getJson('/api/admin/organization-proposals')->assertForbidden();
        $this->patchJson("/api/admin/organization-proposals/{$proposal->id}", ['status' => 'declined'])
            ->assertForbidden();

        Sanctum::actingAs($president);
        $this->getJson('/api/admin/organization-proposals')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'submitted');
        $this->getJson('/api/admin/organization-proposals?status=all')
            ->assertOk()
            ->assertJsonMissing(['id' => $draft->id]);
        $this->patchJson("/api/admin/organization-proposals/{$proposal->id}", [
            'status' => 'declined',
            'decision_note' => 'Please revise the proposal.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.decision_note', 'Please revise the proposal.');
    }

    public function test_all_officer_roles_can_create_proposals_but_members_cannot_create_edit_or_submit(): void
    {
        foreach (['President', 'Vice President', 'Treasurer', 'Secretary', 'P.I.O', 'Auditor', 'Officer'] as $index => $role) {
            $officer = User::factory()->create();
            $this->addMembership($officer, $role);
            Sanctum::actingAs($officer);

            $this->postJson('/api/organization-proposals', [
                ...$this->proposalPayload(),
                'name' => "Officer Proposal {$index}",
            ])->assertCreated();
        }

        $member = User::factory()->create();
        $this->addMembership($member, 'Member');
        $draft = OrganizationProposal::create([
            'user_id' => $member->id,
            ...$this->proposalPayload(),
            'status' => 'draft',
        ]);

        Sanctum::actingAs($member);
        $this->postJson('/api/organization-proposals', $this->proposalPayload())
            ->assertForbidden();
        $this->putJson("/api/organization-proposals/{$draft->id}", $this->proposalPayload())
            ->assertForbidden();
        $this->patchJson("/api/organization-proposals/{$draft->id}/submit")
            ->assertForbidden();
    }

    public function test_proposal_validation_and_ownership_are_enforced(): void
    {
        $proposer = User::factory()->create();
        $this->addMembership($proposer, 'Secretary');
        $otherUser = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $organization = Organization::create([
            'name' => 'Existing Organization',
            'category' => 'Academic',
            'college' => 'CCS',
            'cause' => 'Community',
            'mission' => 'Existing mission.',
        ]);
        $proposal = OrganizationProposal::create([
            'user_id' => $proposer->id,
            ...$this->proposalPayload(),
            'status' => 'draft',
        ]);

        Sanctum::actingAs($proposer);
        $this->postJson('/api/organization-proposals', [
            ...$this->proposalPayload(),
            'name' => $organization->name,
        ])->assertConflict();
        $this->postJson('/api/organization-proposals', [
            ...$this->proposalPayload(),
            'college' => 'UNKNOWN',
        ])->assertUnprocessable();

        Sanctum::actingAs($otherUser);
        $this->putJson("/api/organization-proposals/{$proposal->id}", $this->proposalPayload())
            ->assertForbidden();

        Sanctum::actingAs($admin);
        $this->putJson("/api/organization-proposals/{$proposal->id}", $this->proposalPayload())
            ->assertForbidden();
    }

    private function proposalPayload(): array
    {
        return [
            'name' => 'Campus Green Alliance',
            'category' => 'Environmental',
            'college' => 'CCS',
            'cause' => 'Campus sustainability',
            'mission' => 'Help students build a greener campus.',
        ];
    }

    private function addMembership(User $user, string $role): void
    {
        $organization = Organization::create([
            'name' => "Eligibility Organization {$user->id}",
            'category' => 'Academic',
            'college' => 'CCS',
            'cause' => 'Community',
            'mission' => 'Support the campus community.',
        ]);

        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }
}
