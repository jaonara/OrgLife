<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_makes_jao_the_president_of_every_organization_and_removes_the_legacy_user(): void
    {
        config([
            'orglife.vice_president_password' => 'vice-president-test-password',
            'orglife.treasurer_password' => 'treasurer-test-password',
        ]);
        $jao = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'jaonara@gmail.com',
            'password' => Hash::make('existing-password'),
        ]);
        $legacyUser = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
        $organization = Organization::create([
            'name' => 'Computer Society',
            'category' => 'Academic',
            'college' => 'CCS',
            'cause' => 'Technology and Innovation',
            'mission' => 'Build an inclusive technology community.',
            'open_membership' => true,
            'color' => '#dbeafe',
        ]);
        Organization::create([
            'name' => 'Campus Creatives',
            'category' => 'Arts',
            'college' => 'CBA',
            'cause' => 'Arts, Media, and Creativity',
            'mission' => 'Give student artists a welcoming place to collaborate.',
            'open_membership' => true,
            'color' => '#f3e8ff',
        ]);

        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $jao->id,
            'role' => 'Officer',
        ]);
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $legacyUser->id,
            'role' => 'President',
        ]);
        $announcement = OrganizationAnnouncement::create([
            'organization_id' => $organization->id,
            'user_id' => $legacyUser->id,
            'title' => 'Welcome',
            'body' => 'Welcome to the organization.',
        ]);

        $this->seed();

        $jao->refresh();
        $this->assertSame('Jao Nara', $jao->name);
        $this->assertTrue($jao->is_admin);
        $this->assertTrue(Hash::check('existing-password', $jao->password));
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        $this->assertDatabaseHas('organization_announcements', [
            'id' => $announcement->id,
            'user_id' => $jao->id,
        ]);
        $vicePresident = User::where('email', 'magbaril@gmail.com')->firstOrFail();
        $treasurer = User::where('email', 'sapra@gmail.com')->firstOrFail();
        $this->assertSame('Samantha Eve Magbaril', $vicePresident->name);
        $this->assertSame('Asnawi Sapra', $treasurer->name);
        $this->assertTrue(Hash::check('vice-president-test-password', $vicePresident->password));
        $this->assertTrue(Hash::check('treasurer-test-password', $treasurer->password));

        $criminology = Organization::where('college', 'COC')->firstOrFail();
        $this->assertSame('College of Criminology', $criminology->name);
        $this->assertDatabaseHas('organization_announcements', [
            'organization_id' => $criminology->id,
            'user_id' => $jao->id,
            'title' => 'Advancing Forensic Knowledge and Skills',
        ]);

        $education = Organization::where('college', 'CED')->firstOrFail();
        $this->assertSame('College of Education', $education->name);
        $teachersDayAnnouncement = OrganizationAnnouncement::where('organization_id', $education->id)
            ->where('title', 'HAPPY TEACHERS’ DAY! 💙📚')
            ->firstOrFail();
        $this->assertSame('2026-10-20', $teachersDayAnnouncement->created_at->toDateString());
        $this->assertStringContainsString('SPC Covered Court', $teachersDayAnnouncement->body);
        $this->assertSame($jao->id, $teachersDayAnnouncement->user_id);

        $businessAdministration = Organization::where('name', 'College of Business Administration')->firstOrFail();
        $researchAnnouncement = OrganizationAnnouncement::where('organization_id', $businessAdministration->id)
            ->where('title', 'like', '%Research Publication%')
            ->firstOrFail();
        $this->assertStringContainsString('ABL Publisher Opc', $researchAnnouncement->body);
        $this->assertSame(now()->toDateString(), $researchAnnouncement->created_at->toDateString());
        $this->assertSame($jao->id, $researchAnnouncement->user_id);

        $artsAndSciences = Organization::where('college', 'CAS')->firstOrFail();
        $this->assertSame('College of Arts and Sciences', $artsAndSciences->name);
        $museumsAnnouncement = OrganizationAnnouncement::where('organization_id', $artsAndSciences->id)
            ->where('title', 'Museums and Galleries Month Celebration')
            ->firstOrFail();
        $this->assertStringContainsString('Crafting Opportunities for the New World', $museumsAnnouncement->body);
        $this->assertSame(now()->toDateString(), $museumsAnnouncement->created_at->toDateString());
        $this->assertSame($jao->id, $museumsAnnouncement->user_id);

        $engineering = Organization::where('college', 'COE')->firstOrFail();
        $this->assertSame('College of Engineering', $engineering->name);
        $engineeringAnnouncement = OrganizationAnnouncement::where('organization_id', $engineering->id)
            ->where('title', '𝗦𝗣𝗖-𝗝𝗣𝗜𝗖𝗘 | 𝗦𝗧𝗥𝗘𝗡𝗚𝗧𝗛𝗘𝗡𝗜𝗡𝗚 𝗖𝗢𝗡𝗡𝗘𝗖𝗧𝗜𝗢𝗡𝗦 𝗔𝗧 𝗧𝗛𝗘 𝗣𝗜𝗖𝗘–𝗜𝗖𝗟𝗗𝗡 𝗚𝗘𝗡𝗘𝗥𝗔𝗟 𝗔𝗦𝗦𝗘𝗠𝗕𝗟𝗬 𝟮𝟬𝟮𝟲 🏗️👷')
            ->firstOrFail();
        $this->assertStringContainsString('JPICE', $engineeringAnnouncement->body);
        $this->assertStringContainsString('#PICEGeneralAssembly2026', $engineeringAnnouncement->body);
        $this->assertSame(now()->toDateString(), $engineeringAnnouncement->created_at->toDateString());
        $this->assertSame($jao->id, $engineeringAnnouncement->user_id);

        $organizations = Organization::query()->get();
        $this->assertGreaterThan(0, $organizations->count());
        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'name' => 'College of Computer Studies',
        ]);
        $this->assertDatabaseMissing('organizations', ['name' => 'Computer Society']);
        $this->assertDatabaseMissing('organizations', ['name' => 'Campus Creatives']);
        $this->assertDatabaseMissing('organizations', ['name' => 'Environmental Advocates']);
        $ccsTeachersDay = OrganizationAnnouncement::where('organization_id', $organization->id)
            ->where('title', 'Happy Teachers’ Day! Thank you for being our mentors')
            ->firstOrFail();
        $this->assertStringContainsString('#CSSO', $ccsTeachersDay->body);
        $this->assertSame(now()->subDays(4)->toDateString(), $ccsTeachersDay->created_at->toDateString());
        $this->assertSame($jao->id, $ccsTeachersDay->user_id);
        $this->assertDatabaseHas('organization_events', [
            'organization_id' => $organization->id,
            'title' => 'CCS Teachers’ Day Appreciation',
            'starts_at' => '2026-10-19 10:00:00',
        ]);
        $this->assertDatabaseHas('organization_events', [
            'organization_id' => $engineering->id,
            'title' => 'PICE ICLDN Chapter General Assembly 2026',
            'starts_at' => '2026-10-17 09:00:00',
        ]);
        $this->assertDatabaseHas('organization_events', [
            'organization_id' => $education->id,
            'title' => 'Teachers’ Day 2026',
            'starts_at' => '2026-10-21 15:30:00',
        ]);
        foreach ($organizations as $seededOrganization) {
            $this->assertTrue(
                $seededOrganization->events()->where('starts_at', '<', now())->exists(),
                "{$seededOrganization->name} should have a seeded past event.",
            );
        }
        foreach ($organizations as $seededOrganization) {
            $this->assertDatabaseHas('organization_members', [
                'organization_id' => $seededOrganization->id,
                'user_id' => $jao->id,
                'role' => 'President',
            ]);
            $this->assertDatabaseHas('organization_members', [
                'organization_id' => $seededOrganization->id,
                'user_id' => $vicePresident->id,
                'role' => 'Vice President',
            ]);
            $this->assertDatabaseHas('organization_members', [
                'organization_id' => $seededOrganization->id,
                'user_id' => $treasurer->id,
                'role' => 'Treasurer',
            ]);
            $this->assertSame(
                1,
                OrganizationMember::query()
                    ->where('organization_id', $seededOrganization->id)
                    ->where('role', 'President')
                    ->count(),
            );
        }
    }
}
