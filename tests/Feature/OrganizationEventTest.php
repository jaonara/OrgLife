<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationEvent;
use App\Models\OrganizationEventAttendance;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_can_rsvp_and_cancel_for_upcoming_events(): void
    {
        $member = User::factory()->create();
        $organization = $this->organization();
        $event = $this->event($organization, now()->addWeek());
        $this->membership($organization, $member);

        Sanctum::actingAs($member);
        $this->postJson("/api/organizations/{$organization->id}/events/{$event->id}/rsvp")
            ->assertOk()
            ->assertJsonPath('data.rsvped', true);
        $this->assertDatabaseHas('organization_event_attendances', [
            'organization_event_id' => $event->id,
            'user_id' => $member->id,
        ]);

        $this->getJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.events.0.rsvped', true)
            ->assertJsonPath('data.events.0.rsvp_count', 1);
        $this->getJson('/api/my-events')
            ->assertOk()
            ->assertJsonPath('data.0.rsvped', true);

        $this->deleteJson("/api/organizations/{$organization->id}/events/{$event->id}/rsvp")
            ->assertOk()
            ->assertJsonPath('data.rsvped', false);
        $this->getJson('/api/my-events')
            ->assertOk()
            ->assertJsonPath('data.0.rsvped', false)
            ->assertJsonPath('data.0.rsvp_count', 0);
    }

    public function test_nonmembers_cannot_rsvp_and_members_cannot_rsvp_to_past_events(): void
    {
        $member = User::factory()->create();
        $nonMember = User::factory()->create();
        $organization = $this->organization();
        $futureEvent = $this->event($organization, now()->addWeek());
        $pastEvent = $this->event($organization, now()->subWeek());
        $this->membership($organization, $member);

        Sanctum::actingAs($nonMember);
        $this->postJson("/api/organizations/{$organization->id}/events/{$futureEvent->id}/rsvp")
            ->assertForbidden();

        Sanctum::actingAs($member);
        $this->postJson("/api/organizations/{$organization->id}/events/{$pastEvent->id}/rsvp")
            ->assertConflict();
    }

    public function test_officers_can_mark_member_attendance_and_counts_are_visible(): void
    {
        $officer = User::factory()->create();
        $member = User::factory()->create();
        $nonMember = User::factory()->create();
        $organization = $this->organization();
        $event = $this->event($organization, now()->subDay());
        $this->membership($organization, $officer, 'Officer');
        $this->membership($organization, $member);

        Sanctum::actingAs($member);
        $this->getJson("/api/officer/organizations/{$organization->id}/events")
            ->assertForbidden();
        $this->postJson("/api/officer/events/{$event->id}/attendance/{$nonMember->id}")
            ->assertForbidden();

        Sanctum::actingAs($officer);
        $this->getJson("/api/officer/organizations/{$organization->id}/events")
            ->assertOk()
            ->assertJsonPath('data.0.attendance_count', 0);
        $this->getJson("/api/officer/events/{$event->id}/attendance")
            ->assertOk()
            ->assertJsonFragment(['user_id' => $member->id, 'attended' => false]);
        $this->postJson("/api/officer/events/{$event->id}/attendance/{$member->id}")
            ->assertOk()
            ->assertJsonPath('data.attended', true);
        $this->postJson("/api/officer/events/{$event->id}/attendance/{$member->id}")
            ->assertOk();

        $this->assertSame(1, OrganizationEventAttendance::where('organization_event_id', $event->id)
            ->whereNotNull('attended_at')
            ->count());

        Sanctum::actingAs($member);
        $this->getJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.events.0.attendance_count', 1)
            ->assertJsonPath('data.events.0.is_past', true);
        $this->getJson('/api/my-events')
            ->assertOk()
            ->assertJsonPath('data.0.is_past', true)
            ->assertJsonPath('data.0.attendance_count', 1);
    }

    public function test_event_routes_require_membership_and_event_organization_match(): void
    {
        $member = User::factory()->create();
        $organization = $this->organization();
        $otherOrganization = $this->organization(['name' => 'Another Organization']);
        $event = $this->event($otherOrganization, now()->addDay());
        $this->membership($organization, $member);

        Sanctum::actingAs($member);
        $this->postJson("/api/organizations/{$organization->id}/events/{$event->id}/rsvp")
            ->assertNotFound();
        $this->getJson('/api/my-events')
            ->assertOk()
            ->assertJsonCount(0, 'data');
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

    private function event(Organization $organization, $startsAt): OrganizationEvent
    {
        return OrganizationEvent::create([
            'organization_id' => $organization->id,
            'title' => 'Community Meetup',
            'description' => 'Meet the organization members.',
            'location' => 'Campus Hall',
            'starts_at' => $startsAt,
        ]);
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
