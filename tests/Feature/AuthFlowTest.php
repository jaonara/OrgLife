<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_log_in(): void
    {
        $registration = $this->postJson('/api/register', [
            'name' => 'OrgLife Tester',
            'email' => 'orglife-tester@example.test',
            'password' => 'Testing123!',
            'password_confirmation' => 'Testing123!',
        ]);

        $registration
            ->assertCreated()
            ->assertJsonPath('user.email', 'orglife-tester@example.test')
            ->assertJsonStructure(['user', 'token']);

        $this->assertDatabaseHas('users', [
            'email' => 'orglife-tester@example.test',
        ]);

        $login = $this->postJson('/api/login', [
            'email' => 'orglife-tester@example.test',
            'password' => 'Testing123!',
        ]);

        $login
            ->assertOk()
            ->assertJsonPath('user.email', 'orglife-tester@example.test')
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_a_user_can_log_out_and_the_token_is_revoked(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Testing123!'),
        ]);
        $accessToken = $user->createToken('test-token');
        $token = $accessToken->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $accessToken->accessToken->id,
        ]);
    }
}
