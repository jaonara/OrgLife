<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationDue;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DuesManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_treasurers_and_presidents_can_create_and_update_school_year_dues(): void
    {
        $treasurer = User::factory()->create();
        $president = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $treasurer, 'Treasurer');
        $this->membership($organization, $president, 'President');
        $this->membership($organization, $member);

        Sanctum::actingAs($treasurer);
        $this->postJson("/api/officer/organizations/{$organization->id}/dues", [
            'school_year' => '2026-2027',
            'amount' => '100.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.school_year', '2026-2027')
            ->assertJsonPath('data.amount', '100.00');

        $due = OrganizationDue::firstOrFail();
        $this->getJson("/api/officer/organizations/{$organization->id}/dues")
            ->assertOk()
            ->assertJsonFragment([
                'user_id' => $member->id,
                'status' => 'unpaid',
            ]);

        Sanctum::actingAs($president);
        $this->postJson("/api/officer/organizations/{$organization->id}/dues", [
            'school_year' => '2026-2027',
            'amount' => '120.00',
            'due_date' => now()->addDays(14)->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $due->id)
            ->assertJsonPath('data.amount', '120.00');

        Sanctum::actingAs($member);
        $this->postJson("/api/officer/organizations/{$organization->id}/dues", [
            'school_year' => '2027',
            'amount' => '10',
            'due_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_admin_can_view_and_manage_any_organizations_dues(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $member = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $member);
        $due = $organization->dues()->create([
            'school_year' => '2026-2027',
            'amount' => '100.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/officer/organizations/{$organization->id}/dues")
            ->assertOk()
            ->assertJsonFragment([
                'user_id' => $member->id,
                'status' => 'unpaid',
            ]);
        $this->postJson("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments", [
            'user_id' => $member->id,
            'amount' => '100.00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.member_name', $member->name)
            ->assertJsonPath('data.balance', '0.00');
    }

    public function test_members_submit_gcash_proof_pending_review_and_approved_payment_updates_balance(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $member = User::factory()->create();
        $president = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $member);
        $this->membership($organization, $president, 'President');
        $due = $organization->dues()->create([
            'school_year' => '2026-2027',
            'amount' => '100.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);
        $organization->update(['gcash_number' => '09171234567']);

        Sanctum::actingAs($member);
        $submission = $this->post("/api/my-dues/{$due->id}/payments", [
            'amount' => '25.00',
            'reference_last_four' => '4321',
            'proof' => UploadedFile::fake()->image('proof.png'),
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.payment_id');

        $payment = $due->payments()->findOrFail($submission);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->getJson('/api/my-dues')
            ->assertOk()
            ->assertJsonPath('data.0.paid_amount', '0.00')
            ->assertJsonPath('data.0.pending_amount', '25.00')
            ->assertJsonPath('data.0.payments.0.status', 'pending');

        $this->post("/api/my-dues/{$due->id}/payments", [
            'amount' => '75.01',
            'reference_last_four' => '9876',
        ])->assertUnprocessable();

        $this->getJson('/api/officer/pending-dues-payments')->assertForbidden();
        Sanctum::actingAs($president);
        $this->getJson('/api/officer/pending-dues-payments')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
        $managerResponse = $this->getJson("/api/officer/organizations/{$organization->id}/dues")
            ->assertOk();
        $memberRow = collect($managerResponse->json('data.dues.0.members'))->firstWhere('user_id', $member->id);
        $this->assertSame('pending', $memberRow['payments'][0]['status']);
        $this->assertTrue($memberRow['payments'][0]['has_proof']);

        $this->get("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments/{$submission}/proof")
            ->assertOk();
        $this->patchJson("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments/{$submission}/review", [
            'status' => 'approved',
        ])->assertOk();

        Sanctum::actingAs($member);
        $this->getJson('/api/my-dues')
            ->assertOk()
            ->assertJsonPath('data.0.paid_amount', '25.00')
            ->assertJsonPath('data.0.pending_amount', '0.00');

        Sanctum::actingAs($president);
        $this->patchJson("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments/{$submission}/review", [
            'status' => 'pending',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
        $this->getJson('/api/officer/pending-dues-payments')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
        Sanctum::actingAs($member);
        $this->getJson('/api/my-dues')
            ->assertOk()
            ->assertJsonPath('data.0.paid_amount', '0.00')
            ->assertJsonPath('data.0.pending_amount', '25.00');

        Sanctum::actingAs($president);
        $this->patchJson("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments/{$submission}/review", [
            'status' => 'approved',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        Sanctum::actingAs($member);
        $this->getJson('/api/my-dues')
            ->assertOk()
            ->assertJsonPath('data.0.paid_amount', '25.00');
        Sanctum::actingAs($president);
        $this->getJson('/api/officer/pending-dues-payments')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_members_can_edit_their_pending_payment_details_and_replace_proof_until_reviewed(): void
    {
        Storage::fake('local');
        $member = User::factory()->create();
        $otherMember = User::factory()->create();
        $president = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $member);
        $this->membership($organization, $otherMember);
        $this->membership($organization, $president, 'President');
        $due = $organization->dues()->create([
            'school_year' => '2026-2027',
            'amount' => '100.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);
        $organization->update(['gcash_number' => '09171234567']);

        Sanctum::actingAs($member);
        $paymentId = $this->post("/api/my-dues/{$due->id}/payments", [
            'amount' => '25.00',
            'reference_last_four' => '1111',
            'proof' => UploadedFile::fake()->image('original.png'),
        ])->assertCreated()->json('data.payment_id');
        $payment = $due->payments()->findOrFail($paymentId);
        $originalProofPath = $payment->proof_path;

        $this->post("/api/my-dues/{$due->id}/payments/{$paymentId}", [
            '_method' => 'PATCH',
            'amount' => '40.00',
            'reference_last_four' => '2222',
            'proof' => UploadedFile::fake()->image('replacement.png'),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '40.00');

        $payment->refresh();
        $this->assertSame('2222', $payment->reference_last_four);
        $this->assertNotSame($originalProofPath, $payment->proof_path);
        Storage::disk('local')->assertMissing($originalProofPath);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->getJson('/api/my-dues')
            ->assertOk()
            ->assertJsonPath('data.0.pending_amount', '40.00')
            ->assertJsonPath('data.0.payments.0.has_proof', true);

        Sanctum::actingAs($otherMember);
        $this->post("/api/my-dues/{$due->id}/payments/{$paymentId}", [
            '_method' => 'PATCH',
            'amount' => '30.00',
            'reference_last_four' => '3333',
        ])->assertForbidden();

        Sanctum::actingAs($president);
        $this->patchJson("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments/{$paymentId}/review", [
            'status' => 'approved',
        ])->assertOk();

        Sanctum::actingAs($member);
        $this->post("/api/my-dues/{$due->id}/payments/{$paymentId}", [
            '_method' => 'PATCH',
            'amount' => '30.00',
            'reference_last_four' => '3333',
        ])->assertStatus(409);
    }

    public function test_gcash_details_require_dues_manager_and_member_submissions_require_proof_or_reference(): void
    {
        Storage::fake('public');
        $treasurer = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $treasurer, 'Treasurer');
        $this->membership($organization, $member);
        $due = $organization->dues()->create([
            'school_year' => '2026-2027',
            'amount' => '100.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);

        Sanctum::actingAs($member);
        $this->post("/api/officer/organizations/{$organization->id}/gcash", [
            'gcash_number' => '09171234567',
        ])->assertForbidden();

        Sanctum::actingAs($treasurer);
        $this->post("/api/officer/organizations/{$organization->id}/gcash", [
            'gcash_number' => '09171234567',
        ])->assertOk();

        Sanctum::actingAs($member);
        $this->post("/api/my-dues/{$due->id}/payments", [
            'amount' => '10.00',
        ])->assertUnprocessable();

        Sanctum::actingAs($treasurer);
        $this->post("/api/officer/organizations/{$organization->id}/gcash", [
            'gcash_qr' => UploadedFile::fake()->image('gcash.png'),
        ])
            ->assertOk()
            ->assertJsonPath('data.gcash_number', '09171234567');

        $organization->refresh();
        $this->assertNotNull($organization->gcash_qr_path);
        Storage::disk('public')->assertExists($organization->gcash_qr_path);
    }

    public function test_treasurer_records_partial_payments_and_member_can_view_status_and_receipt_data(): void
    {
        $treasurer = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $treasurer, 'Treasurer');
        $this->membership($organization, $member);
        $due = $organization->dues()->create([
            'school_year' => '2026-2027',
            'amount' => '100.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);

        Sanctum::actingAs($treasurer);
        $this->postJson("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments", [
            'user_id' => $member->id,
            'amount' => '25.00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.member_name', $member->name)
            ->assertJsonPath('data.payment_amount', '25.00')
            ->assertJsonPath('data.total_paid', '25.00')
            ->assertJsonPath('data.balance', '75.00');

        Sanctum::actingAs($member);
        $this->getJson('/api/my-dues')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'partial')
            ->assertJsonPath('data.0.paid_amount', '25.00')
            ->assertJsonPath('data.0.balance', '75.00');

        Sanctum::actingAs($treasurer);
        $this->postJson("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments", [
            'user_id' => $member->id,
            'amount' => '75.01',
        ])->assertUnprocessable();

        $this->postJson("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments", [
            'user_id' => $member->id,
            'amount' => '75.00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.balance', '0.00');

        Sanctum::actingAs($member);
        $this->getJson('/api/my-dues')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'paid');

        Sanctum::actingAs($treasurer);
        $this->getJson("/api/officer/organizations/{$organization->id}/dues")
            ->assertOk()
            ->assertJsonFragment([
                'user_id' => $member->id,
                'status' => 'paid',
            ]);
    }

    public function test_dues_managers_can_correct_payment_amounts_without_exceeding_member_dues(): void
    {
        $treasurer = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $this->membership($organization, $treasurer, 'Treasurer');
        $this->membership($organization, $member);
        $due = $organization->dues()->create([
            'school_year' => '2026-2027',
            'amount' => '100.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);
        $firstPayment = $due->payments()->create([
            'user_id' => $member->id,
            'recorded_by' => $treasurer->id,
            'amount' => '25.00',
            'paid_at' => now()->subDay(),
        ]);
        $due->payments()->create([
            'user_id' => $member->id,
            'recorded_by' => $treasurer->id,
            'amount' => '25.00',
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($treasurer);
        $managementResponse = $this->getJson("/api/officer/organizations/{$organization->id}/dues")
            ->assertOk();
        $memberDues = collect($managementResponse->json('data.dues.0.members'))
            ->firstWhere('user_id', $member->id);
        $this->assertSame('50.00', $memberDues['paid_amount']);
        $this->assertSame('50.00', $memberDues['balance']);
        $this->assertSame($firstPayment->id, $memberDues['payments'][1]['id']);

        $this->patchJson("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments/{$firstPayment->id}", [
            'amount' => '80.00',
        ])->assertUnprocessable();

        $this->patchJson("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments/{$firstPayment->id}", [
            'amount' => '60.00',
        ])
            ->assertOk()
            ->assertJsonPath('data.payment_amount', '60.00')
            ->assertJsonPath('data.total_paid', '85.00')
            ->assertJsonPath('data.balance', '15.00');

        Sanctum::actingAs($member);
        $this->patchJson("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments/{$firstPayment->id}", [
            'amount' => '10.00',
        ])->assertForbidden();
    }

    public function test_dues_management_rejects_amounts_below_payments_and_unrelated_organizations(): void
    {
        $treasurer = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->organization();
        $otherOrganization = $this->organization(['name' => 'Other Organization']);
        $this->membership($organization, $treasurer, 'Treasurer');
        $this->membership($organization, $member);
        $due = $organization->dues()->create([
            'school_year' => '2026-2027',
            'amount' => '50.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);
        $due->payments()->create([
            'user_id' => $member->id,
            'amount' => '30.00',
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($treasurer);
        $this->postJson("/api/officer/organizations/{$organization->id}/dues", [
            'school_year' => '2026-2027',
            'amount' => '20.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ])->assertConflict();

        $this->getJson("/api/officer/organizations/{$otherOrganization->id}/dues")
            ->assertForbidden();

        $this->postJson("/api/officer/organizations/{$organization->id}/dues/{$due->id}/payments", [
            'user_id' => User::factory()->create()->id,
            'amount' => '1.00',
        ])->assertUnprocessable();
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
