<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
        Notification::fake();
    }

    public function test_forgot_password_uses_the_same_response_for_known_and_unknown_emails(): void
    {
        $user = $this->createUser();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inexistente@empresa.com.br']);

        $known->assertOk()->assertExactJson([
            'message' => 'Se o e-mail estiver cadastrado, enviaremos as instruções de redefinição.',
        ]);
        $unknown->assertOk()->assertExactJson($known->json());
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_reset_password_completes_once_and_revokes_existing_api_tokens(): void
    {
        $user = $this->createUser();
        $user->createToken('existing-session');
        $resetToken = $this->requestResetToken($user);
        $payload = $this->resetPayload($user, $resetToken);

        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Senha redefinida com sucesso.');

        $this->assertTrue(Hash::check('NovaSenhaSegura2026', $user->fresh()->password_hash));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);
    }

    public function test_expired_reset_token_is_rejected_and_password_is_unchanged(): void
    {
        $user = $this->createUser();
        $resetToken = $this->requestResetToken($user);

        $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();

        $this->postJson('/api/v1/auth/reset-password', $this->resetPayload($user, $resetToken))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);

        $this->assertTrue(Hash::check('CurrentPassword2026', $user->fresh()->password_hash));
    }

    public function test_reset_rejects_weak_password_without_consuming_the_token(): void
    {
        $user = $this->createUser();
        $resetToken = $this->requestResetToken($user);
        $payload = $this->resetPayload($user, $resetToken, 'weak');

        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertTrue(Hash::check('CurrentPassword2026', $user->fresh()->password_hash));
    }

    private function createUser(): User
    {
        return User::factory()->create([
            'email' => 'ana@empresa.com.br',
            'password_hash' => Hash::make('CurrentPassword2026'),
        ]);
    }

    private function requestResetToken(User $user): string
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        $resetToken = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$resetToken): bool {
            $resetToken = $notification->token;

            return true;
        });

        return $resetToken;
    }

    /**
     * @return array{email: string, token: string, password: string, password_confirmation: string}
     */
    private function resetPayload(User $user, string $token, string $password = 'NovaSenhaSegura2026'): array
    {
        return [
            'email' => $user->email,
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }
}
