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

    public function test_only_the_president_can_remove_members_and_cannot_remove_themselves(): void
    {
        $officer = User::factory()->create();
        $president = User::factory()->create();
        $vicePresident = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $officerMembership = $this->membership($organization, $officer, 'Officer');
        $presidentMembership = $this->membership($organization, $president, 'President');
        $vicePresidentMembership = $this->membership($organization, $vicePresident, 'Vice President');
        $memberMembership = $this->membership($organization, $member);

        Sanctum::actingAs($officer);

        $this->deleteJson("/api/officer/organizations/{$organization->id}/members/{$memberMembership->id}")
            ->assertForbidden();
        $this->assertDatabaseHas('organization_members', ['id' => $memberMembership->id]);
        $this->deleteJson("/api/officer/organizations/{$organization->id}/members/{$vicePresidentMembership->id}")
            ->assertForbidden();

        Sanctum::actingAs($vicePresident);
        $this->deleteJson("/api/officer/organizations/{$organization->id}/members/{$memberMembership->id}")
            ->assertForbidden();

        Sanctum::actingAs($president);
        $this->deleteJson("/api/officer/organizations/{$organization->id}/members/{$memberMembership->id}")
            ->assertOk();
        $this->assertDatabaseMissing('organization_members', ['id' => $memberMembership->id]);
        $this->deleteJson("/api/officer/organizations/{$organization->id}/members/{$officerMembership->id}")
            ->assertOk();
        $this->assertDatabaseMissing('organization_members', ['id' => $officerMembership->id]);
        $this->deleteJson("/api/officer/organizations/{$organization->id}/members/{$presidentMembership->id}")
            ->assertConflict();
        $this->assertDatabaseHas('organization_members', ['id' => $vicePresidentMembership->id]);
    }

    public function test_treasurer_cannot_remove_members(): void
    {
        $treasurer = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $treasurer, 'Treasurer');
        $memberMembership = $this->membership($organization, $member);

        Sanctum::actingAs($treasurer);
        $this->deleteJson("/api/officer/organizations/{$organization->id}/members/{$memberMembership->id}")
            ->assertForbidden();
        $this->assertDatabaseHas('organization_members', ['id' => $memberMembership->id]);
    }

    public function test_only_the_president_can_assign_roles_to_members_and_officers(): void
    {
        $officer = User::factory()->create();
        $president = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $officerMembership = $this->membership($organization, $officer, 'Officer');
        $presidentMembership = $this->membership($organization, $president, 'President');
        $memberMembership = $this->membership($organization, $member);

        Sanctum::actingAs($member);
        $this->patchJson("/api/officer/organizations/{$organization->id}/members/{$memberMembership->id}", ['role' => 'Secretary'])
            ->assertForbidden();

        Sanctum::actingAs($officer);
        $this->patchJson("/api/officer/organizations/{$organization->id}/members/{$memberMembership->id}", ['role' => 'Secretary'])
            ->assertForbidden();

        Sanctum::actingAs($president);
        $this->patchJson("/api/officer/organizations/{$organization->id}/members/{$memberMembership->id}", ['role' => 'Secretary'])
            ->assertOk()
            ->assertJsonPath('data.role', 'Secretary')
            ->assertJsonPath('data.user_id', $member->id);

        $this->assertDatabaseHas('organization_members', [
            'id' => $memberMembership->id,
            'role' => 'Secretary',
        ]);

        $this->patchJson("/api/officer/organizations/{$organization->id}/members/{$officerMembership->id}", ['role' => 'Auditor'])
            ->assertOk()
            ->assertJsonPath('data.role', 'Auditor');
        $this->assertDatabaseHas('organization_members', [
            'id' => $officerMembership->id,
            'role' => 'Auditor',
        ]);
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'id' => $presidentMembership->id,
            'role' => 'President',
        ]);
    }

    public function test_role_assignment_rejects_invalid_roles_and_protects_the_president(): void
    {
        $president = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $presidentMembership = $this->membership($organization, $president, 'President');
        $memberMembership = $this->membership($organization, $member);

        Sanctum::actingAs($president);
        $this->patchJson("/api/officer/organizations/{$organization->id}/members/{$memberMembership->id}", ['role' => 'President'])
            ->assertUnprocessable();
        $this->patchJson("/api/officer/organizations/{$organization->id}/members/{$memberMembership->id}", ['role' => 'Member'])
            ->assertUnprocessable();
        $this->patchJson("/api/officer/organizations/{$organization->id}/members/{$presidentMembership->id}", ['role' => 'Auditor'])
            ->assertConflict();
    }

    public function test_vice_president_and_treasurer_cannot_use_president_tools(): void
    {
        $vicePresident = User::factory()->create();
        $treasurer = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $vicePresident, 'Vice President');
        $this->membership($organization, $treasurer, 'Treasurer');

        Sanctum::actingAs($vicePresident);
        $this->postJson("/api/organizations/{$organization->id}/announcements", [
            'title' => 'Vice President update',
            'body' => 'An update from the vice president.',
        ])->assertForbidden();
        $this->getJson("/api/officer/organizations/{$organization->id}/events")
            ->assertForbidden();
        $this->getJson('/api/officer/requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($treasurer);
        $this->postJson("/api/organizations/{$organization->id}/announcements", [
            'title' => 'Treasurer update',
            'body' => 'An update from the treasurer.',
        ])->assertForbidden();
        $this->getJson("/api/officer/organizations/{$organization->id}/events")
            ->assertForbidden();
        $this->getJson('/api/officer/requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_presidents_can_post_announcements_and_members_see_only_their_organizations(): void
    {
        $officer = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $firstOrganization = $this->organization(['name' => 'Computer Society']);
        $secondOrganization = $this->organization(['name' => 'Arts Collective']);
        $this->membership($firstOrganization, $officer, 'President');
        $this->membership($firstOrganization, $member);
        $this->membership($secondOrganization, $member);
        $this->membership($secondOrganization, $outsider);

        Sanctum::actingAs($officer);
        $this->postJson("/api/organizations/{$firstOrganization->id}/announcements", [
            'title' => 'Welcome back',
            'body' => 'The first meeting is next week.',
        ])->assertCreated()
            ->assertJsonPath('data.organization.name', 'Computer Society')
            ->assertJsonPath('data.organization.college', $firstOrganization->college)
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

    public function test_only_president_can_delete_organization_announcements(): void
    {
        $president = User::factory()->create();
        $vicePresident = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $president, 'President');
        $this->membership($organization, $vicePresident, 'Vice President');
        $announcement = OrganizationAnnouncement::create([
            'organization_id' => $organization->id,
            'user_id' => $president->id,
            'title' => 'Important update',
            'body' => 'Details of the update.',
        ]);

        Sanctum::actingAs($vicePresident);
        $this->deleteJson("/api/organizations/{$organization->id}/announcements/{$announcement->id}")
            ->assertForbidden();
        $this->assertDatabaseHas('organization_announcements', ['id' => $announcement->id]);

        Sanctum::actingAs($president);
        $this->deleteJson("/api/organizations/{$organization->id}/announcements/{$announcement->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Announcement deleted.');
        $this->assertDatabaseMissing('organization_announcements', ['id' => $announcement->id]);
    }

    public function test_admin_can_manage_an_organization_without_membership(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $member = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $member);
        $announcement = OrganizationAnnouncement::create([
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'title' => 'Campus update',
            'body' => 'An update for all members.',
        ]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.announcements.0.id', $announcement->id);
        $this->getJson("/api/organizations/{$organization->id}/members")
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->deleteJson("/api/organizations/{$organization->id}/announcements/{$announcement->id}")
            ->assertOk();

        $request = \App\Models\MembershipRequest::create([
            'organization_id' => $organization->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
        ]);
        $this->getJson('/api/officer/requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $request->id);
        $this->patchJson("/api/officer/requests/{$request->id}", ['status' => 'approved'])
            ->assertOk();
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
