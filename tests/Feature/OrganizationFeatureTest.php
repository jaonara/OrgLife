<?php

namespace Tests\Feature;

use App\Models\MembershipRequest;
use App\Models\Organization;
use App\Models\OrganizationEvent;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_search_filters_and_organization_details_are_available(): void
    {
        $president = User::factory()->create();
        $computerSociety = $this->organization([
            'name' => 'Computer Society',
            'category' => 'Academic',
            'college' => 'CCS',
            'cause' => 'Technology and Innovation',
        ]);
        $allCollegeOrg = $this->organization([
            'name' => 'Student Government',
            'category' => 'Leadership',
            'college' => 'All Colleges',
            'cause' => 'Student Leadership',
        ]);
        $this->organization([
            'name' => 'Campus Creatives',
            'category' => 'Arts',
            'college' => 'CBA',
            'cause' => 'Arts and Media',
            'open_membership' => false,
        ]);

        OrganizationMember::create([
            'organization_id' => $computerSociety->id,
            'user_id' => $president->id,
            'role' => 'President',
        ]);
        OrganizationEvent::create([
            'organization_id' => $computerSociety->id,
            'title' => 'Build Night',
            'description' => 'A collaborative coding event.',
            'location' => 'Lab 1',
            'starts_at' => now()->addWeek(),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/organizations?q=technology')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Computer Society')
            ->assertJsonPath('meta.colleges.0.code', 'CCS')
            ->assertJsonPath('meta.colleges.0.name', 'College of Computer Studies')
            ->assertJsonPath('meta.colleges.5.code', 'COC')
            ->assertJsonPath('meta.colleges.5.name', 'College of Criminology')
            ->assertJsonCount(6, 'meta.colleges');

        $this->getJson('/api/organizations?college=CCS&open=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Student Government']);
        $this->getJson('/api/organizations?college=CCS&type=Academic&open=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Computer Society');

        $this->getJson('/api/organizations/'.$computerSociety->id)
            ->assertOk()
            ->assertJsonPath('data.mission', $computerSociety->mission)
            ->assertJsonPath('data.officers.0.name', $president->name)
            ->assertJsonPath('data.events.0.title', 'Build Night');

        $this->assertNotSame($allCollegeOrg->id, $computerSociety->id);
    }

    public function test_member_can_request_withdraw_and_reapply_and_officer_can_approve(): void
    {
        $student = User::factory()->create();
        $officer = User::factory()->create();
        $organization = $this->organization();
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $officer->id,
            'role' => 'Officer',
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/organizations/{$organization->id}/membership-requests")
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');
        $this->getJson('/api/membership-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'pending');
        $this->deleteJson("/api/organizations/{$organization->id}/membership-requests")
            ->assertOk();
        $this->getJson('/api/membership-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'withdrawn');
        $this->postJson("/api/organizations/{$organization->id}/membership-requests")
            ->assertCreated();

        Sanctum::actingAs($officer);
        $pending = $this->getJson('/api/officer/requests')
            ->assertOk()
            ->assertJsonPath('data.0.user.id', $student->id)
            ->json('data.0.id');
        $this->patchJson("/api/officer/requests/{$pending}", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        Sanctum::actingAs($student);
        $this->getJson('/api/membership-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'approved');
        $this->getJson('/api/my-organizations')
            ->assertOk()
            ->assertJsonPath('data.0.role', 'Member');
        $this->getJson("/api/organizations/{$organization->id}/members")
            ->assertOk()
            ->assertJsonFragment(['name' => $officer->name, 'role' => 'Officer'])
            ->assertJsonFragment(['name' => $student->name, 'role' => 'Member']);
    }

    public function test_only_officers_can_decide_requests_and_declined_requests_show_status(): void
    {
        $student = User::factory()->create();
        $otherMember = User::factory()->create();
        $organization = $this->organization();
        $membershipRequest = MembershipRequest::create([
            'organization_id' => $organization->id,
            'user_id' => $student->id,
            'status' => 'pending',
        ]);
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $otherMember->id,
            'role' => 'Member',
        ]);

        Sanctum::actingAs($otherMember);
        $this->patchJson("/api/officer/requests/{$membershipRequest->id}", ['status' => 'declined'])
            ->assertForbidden();

        $officer = User::factory()->create();
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $officer->id,
            'role' => 'President',
        ]);

        Sanctum::actingAs($officer);
        $this->patchJson("/api/officer/requests/{$membershipRequest->id}", ['status' => 'declined'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');

        Sanctum::actingAs($student);
        $this->getJson('/api/membership-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'declined');
    }

    public function test_closed_organizations_reject_join_requests_and_membership_list_is_protected(): void
    {
        $student = User::factory()->create();
        $organization = $this->organization(['open_membership' => false]);

        Sanctum::actingAs($student);
        $this->postJson("/api/organizations/{$organization->id}/membership-requests")
            ->assertForbidden();
        $this->getJson("/api/organizations/{$organization->id}/members")
            ->assertForbidden();
    }

    private function organization(array $overrides = []): Organization
    {
        return Organization::create(array_merge([
            'name' => 'Computer Society',
            'category' => 'Academic',
            'college' => 'CCS',
            'cause' => 'Technology and Innovation',
            'mission' => 'Build an inclusive technology community.',
            'open_membership' => true,
            'color' => '#dbeafe',
        ], $overrides));
    }
}
