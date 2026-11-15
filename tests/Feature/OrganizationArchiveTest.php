<?php

namespace Tests\Feature;

use App\Models\DuesPayment;
use App\Models\MembershipRequest;
use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationDue;
use App\Models\OrganizationEvent;
use App\Models\OrganizationMember;
use App\Models\OrganizationReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_president_can_archive_their_organization_and_history_is_retained(): void
    {
        $president = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $president->id,
            'role' => 'President',
        ]);
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $member->id,
        ]);
        MembershipRequest::create([
            'organization_id' => $organization->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
        ]);
        OrganizationAnnouncement::create([
            'organization_id' => $organization->id,
            'user_id' => $president->id,
            'title' => 'Community update',
            'body' => 'An update for organization members.',
        ]);
        OrganizationEvent::create([
            'organization_id' => $organization->id,
            'title' => 'Community event',
            'starts_at' => now()->addWeek(),
        ]);
        $due = OrganizationDue::create([
            'organization_id' => $organization->id,
            'school_year' => '2026-2027',
            'amount' => 100,
            'due_date' => now()->addMonth()->toDateString(),
        ]);
        DuesPayment::create([
            'organization_due_id' => $due->id,
            'user_id' => $member->id,
            'amount' => 50,
            'paid_at' => now(),
        ]);
        OrganizationReport::create([
            'organization_id' => $organization->id,
            'reporter_id' => $member->id,
            'reason' => 'This organization information needs a review.',
        ]);

        Sanctum::actingAs($president);
        $this->deleteJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Organization archived. Its history has been retained.');

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'open_membership' => false,
        ]);
        $this->assertNotNull($organization->fresh()->archived_at);
        $this->assertDatabaseHas('organization_members', ['organization_id' => $organization->id]);
        $this->assertDatabaseHas('membership_requests', ['organization_id' => $organization->id]);
        $this->assertDatabaseHas('organization_announcements', ['organization_id' => $organization->id]);
        $this->assertDatabaseHas('organization_events', ['organization_id' => $organization->id]);
        $this->assertDatabaseHas('organization_dues', ['organization_id' => $organization->id]);
        $this->assertDatabaseHas('dues_payments', ['organization_due_id' => $due->id]);
        $this->assertDatabaseHas('organization_reports', ['organization_id' => $organization->id]);

        $this->getJson('/api/organizations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/organizations/{$organization->id}")->assertNotFound();
        $this->getJson('/api/my-organizations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/my-archived-organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', $organization->name)
            ->assertJsonPath('data.0.role', 'President')
            ->assertJsonPath('data.0.members', 2);
        $this->getJson('/api/my-organization-members')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/announcements')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/my-events')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->getJson('/api/admin/organization-reports')
            ->assertOk()
            ->assertJsonPath('data.0.organization.name', $organization->name);
    }

    public function test_only_an_organization_president_or_admin_can_archive_it(): void
    {
        $president = User::factory()->create();
        $vicePresident = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $organization = $this->organization();
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $president->id,
            'role' => 'President',
        ]);
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $vicePresident->id,
            'role' => 'Vice President',
        ]);

        Sanctum::actingAs($vicePresident);
        $this->deleteJson("/api/organizations/{$organization->id}")->assertForbidden();

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/organizations/{$organization->id}")->assertOk();
        $this->assertNotNull($organization->fresh()->archived_at);
        $this->getJson('/api/my-archived-organizations')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($vicePresident);
        $this->getJson('/api/my-archived-organizations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_president_cannot_archive_an_organization_they_do_not_lead(): void
    {
        $president = User::factory()->create();
        $organization = $this->organization();

        Sanctum::actingAs($president);
        $this->deleteJson("/api/organizations/{$organization->id}")->assertForbidden();
        $this->assertNull($organization->fresh()->archived_at);
    }

    private function organization(): Organization
    {
        static $number = 0;
        $number++;

        return Organization::create([
            'name' => "Campus Organization {$number}",
            'category' => 'Academic',
            'college' => 'CCS',
            'cause' => 'Community',
            'mission' => 'Build a welcoming campus community.',
            'open_membership' => true,
        ]);
    }
}
