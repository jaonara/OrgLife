<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_users_without_exposing_passwords(): void
    {
        $user = User::factory()->create();
        $listedUser = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(2, 'users')
            ->assertJsonPath('users.1.email', $listedUser->email)
            ->assertJsonMissingPath('users.0.password');
    }

    public function test_authenticated_user_can_create_a_user(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'new-user@example.test',
                'password' => 'Testing123!',
                'password_confirmation' => 'Testing123!',
            ])
            ->assertCreated()
            ->assertJsonPath('user.email', 'new-user@example.test')
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('users', ['email' => 'new-user@example.test']);
    }

    public function test_user_can_replace_their_profile_with_put(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/users/'.$user->id, [
                'name' => 'Updated Name',
                'email' => 'updated@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated Name')
            ->assertJsonPath('user.email', 'updated@example.test');
    }

    public function test_user_can_partially_update_their_profile_with_patch(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/users/'.$user->id, [
                'name' => 'Patched Name',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Patched Name')
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_user_cannot_update_another_users_profile(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/users/'.$otherUser->id, ['name' => 'Not Allowed'])
            ->assertForbidden();
    }

    public function test_users_endpoints_require_authentication(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
        $this->postJson('/api/users')->assertUnauthorized();
    }
}
