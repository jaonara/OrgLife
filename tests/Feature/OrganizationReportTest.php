<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_report_an_organization_and_admin_can_resolve_the_report(): void
    {
        $reporter = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $organization = Organization::create([
            'name' => 'Reportable Organization',
            'category' => 'Academic',
            'college' => 'CCS',
            'cause' => 'Community',
            'mission' => 'Build a welcoming campus community.',
            'open_membership' => true,
            'color' => '#dbeafe',
        ]);

        Sanctum::actingAs($reporter);
        $reportResponse = $this->postJson("/api/organizations/{$organization->id}/reports", [
            'reason' => 'The listed contact details are inaccurate.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');
        $reportId = $reportResponse->json('data.id');
        $this->getJson('/api/admin/organization-reports')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/organization-reports')
            ->assertOk()
            ->assertJsonPath('data.0.organization.name', $organization->name)
            ->assertJsonPath('data.0.reporter.name', $reporter->name)
            ->assertJsonPath('data.0.reason', 'The listed contact details are inaccurate.');

        $this->patchJson("/api/admin/organization-reports/{$reportId}", [
            'status' => 'resolved',
            'resolution_note' => 'The organization details were corrected.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.reviewer.name', $admin->name);

        $this->assertSame('resolved', OrganizationReport::findOrFail($reportId)->status);
    }

    public function test_report_reason_is_required_and_must_be_descriptive(): void
    {
        $organization = Organization::create([
            'name' => 'Reportable Organization',
            'category' => 'Academic',
            'college' => 'CCS',
            'cause' => 'Community',
            'mission' => 'Build a welcoming campus community.',
            'open_membership' => true,
            'color' => '#dbeafe',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/organizations/{$organization->id}/reports", ['reason' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }
}
