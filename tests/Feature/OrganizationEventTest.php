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

    public function test_organization_calendar_and_my_events_include_upcoming_and_past_events_with_counts(): void
    {
        $member = User::factory()->create();
        $otherMember = User::factory()->create();
        $organization = $this->organization();
        $otherOrganization = $this->organization(['name' => 'Another Organization']);
        $pastEvent = $this->event($organization, now()->subWeek());
        $pastEvent->update(['title' => 'Past Meetup']);
        $upcomingEvent = $this->event($organization, now()->addWeek());
        $upcomingEvent->update(['title' => 'Upcoming Meetup']);
        $this->event($otherOrganization, now()->addDay());
        $this->membership($organization, $member);
        $this->membership($organization, $otherMember);

        OrganizationEventAttendance::create([
            'organization_event_id' => $pastEvent->id,
            'user_id' => $otherMember->id,
            'attended_at' => now()->subWeek(),
        ]);
        OrganizationEventAttendance::create([
            'organization_event_id' => $upcomingEvent->id,
            'user_id' => $member->id,
            'rsvp_at' => now(),
        ]);

        Sanctum::actingAs($member);

        $this->getJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.events')
            ->assertJsonPath('data.events.0.title', 'Past Meetup')
            ->assertJsonPath('data.events.0.is_past', true)
            ->assertJsonPath('data.events.0.attendance_count', 1)
            ->assertJsonPath('data.events.1.title', 'Upcoming Meetup')
            ->assertJsonPath('data.events.1.is_past', false)
            ->assertJsonPath('data.events.1.rsvped', true)
            ->assertJsonPath('data.events.1.rsvp_count', 1);

        $this->getJson('/api/my-events')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Past Meetup')
            ->assertJsonPath('data.0.organization.name', $organization->name)
            ->assertJsonPath('data.0.organization.college', 'CCS')
            ->assertJsonPath('data.0.is_past', true)
            ->assertJsonPath('data.0.attendance_count', 1)
            ->assertJsonPath('data.1.title', 'Upcoming Meetup')
            ->assertJsonPath('data.1.is_past', false)
            ->assertJsonPath('data.1.rsvped', true)
            ->assertJsonPath('data.1.rsvp_count', 1);
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

    public function test_president_can_create_and_edit_future_events_with_seat_limits(): void
    {
        $president = User::factory()->create();
        $officer = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $event = $this->event($organization, now()->addWeek());
        $this->membership($organization, $president, 'President');
        $this->membership($organization, $officer, 'Treasurer');
        $this->membership($organization, $member);

        $newEvent = [
            'title' => 'Campus Design Meetup',
            'description' => 'A student design session.',
            'location' => 'Innovation Hall',
            'starts_at' => now()->addWeeks(2)->format('Y-m-d\TH:i'),
            'seat_limit' => 3,
        ];

        Sanctum::actingAs($officer);
        $this->postJson("/api/organizations/{$organization->id}/events", $newEvent)
            ->assertForbidden();

        Sanctum::actingAs($president);
        $createdId = $this->postJson("/api/organizations/{$organization->id}/events", $newEvent)
            ->assertCreated()
            ->assertJsonPath('data.title', 'Campus Design Meetup')
            ->assertJsonPath('data.location', 'Innovation Hall')
            ->assertJsonPath('data.seat_limit', 3)
            ->json('data.id');

        $this->patchJson("/api/organizations/{$organization->id}/events/{$createdId}", [
            ...$newEvent,
            'title' => 'Updated Design Meetup',
            'seat_limit' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Design Meetup')
            ->assertJsonPath('data.seat_limit', 2);

        $this->patchJson("/api/organizations/{$organization->id}/events/{$event->id}", [
            'title' => '',
            'description' => '',
            'location' => '',
            'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
            'seat_limit' => 0,
        ])->assertUnprocessable();
    }

    public function test_seat_limit_blocks_additional_rsvps_and_cannot_drop_below_existing_rsvps(): void
    {
        $president = User::factory()->create();
        $firstMember = User::factory()->create();
        $secondMember = User::factory()->create();
        $thirdMember = User::factory()->create();
        $organization = $this->organization();
        $event = $this->event($organization, now()->addWeek());
        $this->membership($organization, $president, 'President');
        $this->membership($organization, $firstMember);
        $this->membership($organization, $secondMember);
        $this->membership($organization, $thirdMember);
        $event->update(['seat_limit' => 2]);

        Sanctum::actingAs($firstMember);
        $this->postJson("/api/organizations/{$organization->id}/events/{$event->id}/rsvp")->assertOk();
        $this->postJson("/api/organizations/{$organization->id}/events/{$event->id}/rsvp")->assertOk();

        Sanctum::actingAs($secondMember);
        $this->postJson("/api/organizations/{$organization->id}/events/{$event->id}/rsvp")->assertOk();

        Sanctum::actingAs($thirdMember);
        $this->postJson("/api/organizations/{$organization->id}/events/{$event->id}/rsvp")
            ->assertConflict();

        Sanctum::actingAs($president);
        $this->patchJson("/api/organizations/{$organization->id}/events/{$event->id}", [
            'title' => $event->title,
            'description' => $event->description,
            'location' => 'Campus Hall',
            'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
            'seat_limit' => 1,
        ])->assertConflict();
        $this->patchJson("/api/organizations/{$organization->id}/events/{$event->id}", [
            'title' => $event->title,
            'description' => $event->description,
            'location' => 'Campus Hall',
            'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
            'seat_limit' => 2,
        ])->assertOk();
    }

    public function test_cancelled_event_preserves_history_and_rejects_new_rsvps(): void
    {
        $president = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $event = $this->event($organization, now()->addWeek());
        $this->membership($organization, $president, 'President');
        $this->membership($organization, $member);

        Sanctum::actingAs($member);
        $this->postJson("/api/organizations/{$organization->id}/events/{$event->id}/rsvp")->assertOk();

        Sanctum::actingAs($president);
        $this->patchJson("/api/organizations/{$organization->id}/events/{$event->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.is_cancelled', true)
            ->assertJsonPath('data.rsvp_count', 1);

        $this->assertDatabaseHas('organization_event_attendances', [
            'organization_event_id' => $event->id,
            'user_id' => $member->id,
        ]);
        $this->assertNotNull($event->fresh()->cancelled_at);
        $this->patchJson("/api/organizations/{$organization->id}/events/{$event->id}/cancel")
            ->assertConflict();

        Sanctum::actingAs($member);
        $this->postJson("/api/organizations/{$organization->id}/events/{$event->id}/rsvp")
            ->assertConflict();
        $this->getJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.events.0.is_cancelled', true);
    }

    public function test_president_cannot_edit_or_cancel_another_organizations_event(): void
    {
        $president = User::factory()->create();
        $organization = $this->organization();
        $otherOrganization = $this->organization(['name' => 'Another Organization']);
        $event = $this->event($otherOrganization, now()->addWeek());
        $this->membership($organization, $president, 'President');

        Sanctum::actingAs($president);
        $this->patchJson("/api/organizations/{$organization->id}/events/{$event->id}", [
            'title' => 'Changed event',
            'location' => 'Campus Hall',
            'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
            'seat_limit' => 10,
        ])->assertNotFound();
        $this->patchJson("/api/organizations/{$organization->id}/events/{$event->id}/cancel")
            ->assertNotFound();

        $this->assertDatabaseHas('organization_events', [
            'id' => $event->id,
            'organization_id' => $otherOrganization->id,
            'cancelled_at' => null,
        ]);
    }

    public function test_officers_can_mark_member_attendance_and_counts_are_visible(): void
    {
        $officer = User::factory()->create();
        $vicePresident = User::factory()->create();
        $member = User::factory()->create();
        $nonMember = User::factory()->create();
        $organization = $this->organization();
        $event = $this->event($organization, now()->subDay());
        $this->membership($organization, $officer, 'President');
        $this->membership($organization, $vicePresident, 'Vice President');
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

        Sanctum::actingAs($vicePresident);
        $this->getJson("/api/officer/events/{$event->id}/attendance")
            ->assertForbidden();
        $this->postJson("/api/officer/events/{$event->id}/attendance/{$member->id}")
            ->assertForbidden();

        Sanctum::actingAs($officer);
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

    public function test_only_president_can_delete_events_and_event_attendance_is_cascaded(): void
    {
        $president = User::factory()->create();
        $vicePresident = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $event = $this->event($organization, now()->addWeek());
        $this->membership($organization, $president, 'President');
        $this->membership($organization, $vicePresident, 'Vice President');
        $attendance = OrganizationEventAttendance::create([
            'organization_event_id' => $event->id,
            'user_id' => $member->id,
            'rsvp_at' => now(),
        ]);

        Sanctum::actingAs($vicePresident);
        $this->deleteJson("/api/organizations/{$organization->id}/events/{$event->id}")
            ->assertForbidden();
        $this->assertDatabaseHas('organization_events', ['id' => $event->id]);

        Sanctum::actingAs($president);
        $this->deleteJson("/api/organizations/{$organization->id}/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Event deleted.');
        $this->assertDatabaseMissing('organization_events', ['id' => $event->id]);
        $this->assertDatabaseMissing('organization_event_attendances', ['id' => $attendance->id]);
    }

    public function test_admin_can_delete_events_in_any_organization(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $organization = $this->organization();
        $event = $this->event($organization, now()->addWeek());

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/organizations/{$organization->id}/events/{$event->id}")
            ->assertOk();
        $this->assertDatabaseMissing('organization_events', ['id' => $event->id]);
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
