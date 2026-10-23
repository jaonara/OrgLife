<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_their_name_and_profile_picture(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $oldPath = "profile-pictures/{$user->id}/old.png";
        Storage::disk('public')->put($oldPath, 'old image');
        $user->profile_picture_path = $oldPath;
        $user->save();
        Sanctum::actingAs($user);

        $response = $this->post('/api/profile', [
            '_method' => 'PATCH',
            'name' => 'Updated Profile Name',
            'profile_picture' => UploadedFile::fake()->image('profile.png', 256, 256),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('user.name', 'Updated Profile Name')
            ->assertJsonPath('user.email', $user->email);

        $user->refresh();
        $this->assertSame('Updated Profile Name', $user->name);
        $this->assertNotNull($user->profile_picture_path);
        Storage::disk('public')->assertExists($user->profile_picture_path);
        Storage::disk('public')->assertMissing($oldPath);
        $response->assertJsonPath(
            'user.profile_picture_url',
            url(Storage::disk('public')->url($user->profile_picture_path)),
        )->assertJsonMissingPath('user.profile_picture_path');
    }

    public function test_user_can_remove_their_profile_picture(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $path = "profile-pictures/{$user->id}/avatar.png";
        Storage::disk('public')->put($path, 'image');
        $user->profile_picture_path = $path;
        $user->save();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/profile-picture')
            ->assertOk()
            ->assertJsonPath('user.profile_picture_url', null);

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($user->fresh()->profile_picture_path);
    }

    public function test_profile_picture_must_be_a_supported_image_within_the_size_limit(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->post('/api/profile', [
            '_method' => 'PATCH',
            'name' => 'Updated Name',
            'profile_picture' => UploadedFile::fake()->create('avatar.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile_picture');

        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertNull($user->fresh()->profile_picture_path);
    }

    public function test_profile_picture_is_included_where_organization_users_are_displayed(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $path = "profile-pictures/{$user->id}/avatar.webp";
        Storage::disk('public')->put($path, 'image');
        $user->profile_picture_path = $path;
        $user->save();
        $organization = Organization::create([
            'name' => 'Profile Picture Test Organization',
            'category' => 'Academic',
            'college' => 'CCS',
            'cause' => 'Community',
            'mission' => 'Build a welcoming community.',
            'open_membership' => true,
            'color' => '#dbeafe',
        ]);
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'President',
        ]);
        OrganizationAnnouncement::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'title' => 'A profile picture announcement',
            'body' => 'This announcement should include the author avatar.',
        ]);
        Sanctum::actingAs($user);
        $pictureUrl = url(Storage::disk('public')->url($path));

        $this->getJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.officers.0.profile_picture_url', $pictureUrl)
            ->assertJsonPath('data.announcements.0.author.profile_picture_url', $pictureUrl);

        $this->getJson("/api/organizations/{$organization->id}/members")
            ->assertOk()
            ->assertJsonPath('data.0.profile_picture_url', $pictureUrl);
    }
}
