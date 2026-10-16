<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationManagementAndAnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_officer_can_remove_a_regular_member_but_not_themselves_or_the_president(): void
    {
        $officer = User::factory()->create();
        $president = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $officerMembership = $this->membership($organization, $officer, 'Officer');
        $presidentMembership = $this->membership($organization, $president, 'President');
        $memberMembership = $this->membership($organization, $member);

        Sanctum::actingAs($officer);

        $this->deleteJson("/api/officer/organizations/{$organization->id}/members/{$memberMembership->id}")
            ->assertOk();
        $this->assertDatabaseMissing('organization_members', ['id' => $memberMembership->id]);

        $this->deleteJson("/api/officer/organizations/{$organization->id}/members/{$officerMembership->id}")
            ->assertConflict();
        $this->deleteJson("/api/officer/organizations/{$organization->id}/members/{$presidentMembership->id}")
            ->assertConflict();
    }

    public function test_only_officers_can_manage_members_and_promote_a_regular_member(): void
    {
        $officer = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $officer, 'Officer');
        $memberMembership = $this->membership($organization, $member);

        Sanctum::actingAs($member);
        $this->patchJson("/api/officer/organizations/{$organization->id}/members/{$memberMembership->id}")
            ->assertForbidden();

        Sanctum::actingAs($officer);
        $this->patchJson("/api/officer/organizations/{$organization->id}/members/{$memberMembership->id}")
            ->assertOk()
            ->assertJsonPath('data.role', 'Officer')
            ->assertJsonPath('data.user_id', $member->id);

        $this->assertDatabaseHas('organization_members', [
            'id' => $memberMembership->id,
            'role' => 'Officer',
        ]);
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $officer->id,
            'role' => 'Officer',
        ]);
    }

    public function test_officers_can_post_announcements_and_members_see_only_their_organizations(): void
    {
        $officer = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $firstOrganization = $this->organization(['name' => 'Computer Society']);
        $secondOrganization = $this->organization(['name' => 'Arts Collective']);
        $this->membership($firstOrganization, $officer, 'Officer');
        $this->membership($firstOrganization, $member);
        $this->membership($secondOrganization, $member);
        $this->membership($secondOrganization, $outsider);

        Sanctum::actingAs($officer);
        $this->postJson("/api/organizations/{$firstOrganization->id}/announcements", [
            'title' => 'Welcome back',
            'body' => 'The first meeting is next week.',
        ])->assertCreated()
            ->assertJsonPath('data.organization.name', 'Computer Society')
            ->assertJsonPath('data.author.name', $officer->name);

        Sanctum::actingAs($member);
        $this->getJson('/api/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Welcome back');
        $this->getJson("/api/organizations/{$firstOrganization->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.announcements');

        Sanctum::actingAs($outsider);
        $this->getJson('/api/announcements')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/organizations/{$firstOrganization->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.announcements');
    }

    public function test_regular_members_cannot_post_and_announcement_content_is_validated(): void
    {
        $member = User::factory()->create();
        $officer = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $member);
        $this->membership($organization, $officer, 'President');

        Sanctum::actingAs($member);
        $this->postJson("/api/organizations/{$organization->id}/announcements", [
            'title' => 'Not permitted',
            'body' => 'This is not an officer post.',
        ])->assertForbidden();

        Sanctum::actingAs($officer);
        $this->postJson("/api/organizations/{$organization->id}/announcements", [
            'title' => '',
            'body' => '',
        ])->assertUnprocessable();
        $this->assertSame(0, OrganizationAnnouncement::count());
    }

    public function test_my_organizations_lists_the_memberships_used_by_the_dashboard(): void
    {
        $member = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $member, 'Officer');

        Sanctum::actingAs($member);
        $this->getJson('/api/my-organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $organization->id)
            ->assertJsonPath('data.0.role', 'Officer');
    }

    private function organization(array $overrides = []): Organization
    {
        return Organization::create(array_merge([
            'name' => 'Campus Organization',
            'category' => 'Academic',
            'college' => 'CCS',
            'cause' => 'Community',
            'mission' => 'Build a welcoming campus community.',
            'open_membership' => true,
            'color' => '#dbeafe',
        ], $overrides));
    }

    private function membership(Organization $organization, User $user, string $role = 'Member'): OrganizationMember
    {
        return OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }
}
