<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_can_login_and_receive_a_two_hour_token(): void
    {
        $user = $this->createUser();
        $expectedExpiry = now()->addHours(2);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword2026',
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.password_hash');

        $this->assertSame($expectedExpiry->toIso8601String(), $response->json('expires_at'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'enerdiag-api',
            'expires_at' => $expectedExpiry->toDateTimeString(),
        ]);
    }

    public function test_invalid_credentials_have_the_same_neutral_unauthenticated_response(): void
    {
        $user = $this->createUser();

        $invalidPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'WrongPassword2026',
        ]);
        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'inexistente@empresa.com.br',
            'password' => 'WrongPassword2026',
        ]);

        $invalidPassword->assertUnauthorized()
            ->assertExactJson([
                'message' => 'E-mail ou senha inválidos.',
                'code' => 'UNAUTHENTICATED',
                'errors' => null,
            ]);
        $unknownEmail->assertUnauthorized()->assertExactJson($invalidPassword->json());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unverified_user_cannot_login(): void
    {
        $user = $this->createUser(verified: false);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword2026',
        ])->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN')
            ->assertJsonPath('message', 'Confirme seu e-mail antes de entrar.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_token_expires_after_two_hours(): void
    {
        $user = $this->createUser();
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword2026',
        ])->assertOk();
        $token = $login->json('token');

        $this->travel(119)->minutes();
        $this->withToken($token)->getJson('/api/v1/user')->assertOk();

        $this->travel(2)->minutes();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->createUser();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'CorrectPassword2026',
        ])->assertOk()->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Sessão encerrada.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    private function createUser(bool $verified = true): User
    {
        return User::factory()->create([
            'email_verified_at' => $verified ? now() : null,
            'password_hash' => Hash::make('CorrectPassword2026'),
        ]);
    }
}
