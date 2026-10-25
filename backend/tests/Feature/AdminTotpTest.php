<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminTotpTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private const string PASSWORD = 'StrongAdminPassword2026!';

    public function test_admin_with_totp_cannot_login_without_a_code_or_with_an_invalid_code(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/v1/auth/login', $this->credentials($admin))
            ->assertUnprocessable()->assertJsonValidationErrors('totp_code');
        $this->postJson('/api/v1/auth/login', $this->credentials($admin) + ['totp_code' => '000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('totp_code');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull($admin->fresh()->totp_last_used_step);
    }

    public function test_valid_code_issues_token_once_and_secret_is_encrypted_and_hidden(): void
    {
        $admin = $this->admin();
        $code = app(TotpService::class)->code(self::SECRET, now()->timestamp);
        $payload = $this->credentials($admin) + ['totp_code' => $code];
        $this->postJson('/api/v1/auth/login', $payload)->assertOk()
            ->assertJsonPath('user.role', 'admin')->assertJsonMissingPath('user.totp_secret');
        $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable()->assertJsonValidationErrors('totp_code');
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotSame(self::SECRET, DB::table('users')->where('id', $admin->id)->value('totp_secret'));
        $this->assertArrayNotHasKey('totp_secret', $admin->fresh()->toArray());
        $this->assertArrayNotHasKey('totp_last_used_step', $admin->fresh()->toArray());
    }

    public function test_expired_code_is_rejected_and_wrong_password_does_not_reveal_totp_requirement(): void
    {
        $admin = $this->admin();
        $code = app(TotpService::class)->code(self::SECRET, now()->timestamp - 90);
        $this->postJson('/api/v1/auth/login', $this->credentials($admin) + ['totp_code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('totp_code');
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'IncorrectPassword'])
            ->assertUnauthorized()->assertJsonPath('errors', null);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_enrollment_confirms_authenticator_and_revokes_existing_sessions(): void
    {
        $this->freezeTime();
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->createToken('old-session');
        $this->partialMock(TotpService::class)->shouldReceive('generateSecret')->once()->andReturn(self::SECRET);
        $code = (new TotpService)->code(self::SECRET, now()->timestamp);
        $this->artisan('admin:configure-security', ['email' => $admin->email])
            ->expectsQuestion('Nova senha (mínimo 14 caracteres, maiúsculas, minúsculas, números e símbolos)', self::PASSWORD)
            ->expectsQuestion('Confirme a nova senha', self::PASSWORD)
            ->expectsQuestion('Código atual do autenticador (6 números)', $code)
            ->assertExitCode(0);
        $this->assertSame(self::SECRET, $admin->fresh()->totp_secret);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->fresh()->password_hash));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_enrollment_rejects_weak_password_without_changing_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $hash = $admin->password_hash;
        $this->artisan('admin:configure-security', ['email' => $admin->email])
            ->expectsQuestion('Nova senha (mínimo 14 caracteres, maiúsculas, minúsculas, números e símbolos)', 'weak')
            ->expectsQuestion('Confirme a nova senha', 'weak')->assertExitCode(1);
        $this->assertNull($admin->fresh()->totp_secret);
        $this->assertSame($hash, $admin->fresh()->password_hash);
    }

    public function test_enrollment_with_invalid_code_keeps_existing_password_secret_and_sessions(): void
    {
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
        $admin = $this->admin();
        $admin->createToken('existing-session');
        $hash = $admin->password_hash;
        $this->partialMock(TotpService::class)->shouldReceive('generateSecret')->once()->andReturn(self::SECRET);
        $this->artisan('admin:configure-security', ['email' => $admin->email])
            ->expectsQuestion('Nova senha (mínimo 14 caracteres, maiúsculas, minúsculas, números e símbolos)', 'AnotherStrongPassword2026!')
            ->expectsQuestion('Confirme a nova senha', 'AnotherStrongPassword2026!')
            ->expectsQuestion('Código atual do autenticador (6 números)', 'invalid')->assertExitCode(1);
        $this->assertSame($hash, $admin->fresh()->password_hash);
        $this->assertSame(self::SECRET, $admin->fresh()->totp_secret);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_configuration_cannot_target_a_customer(): void
    {
        $customer = User::factory()->create();
        $this->artisan('admin:configure-security', ['email' => $customer->email])->assertExitCode(1);
        $this->assertNull($customer->fresh()->totp_secret);
    }

    public function test_password_reset_for_admin_requires_stronger_password_and_preserves_totp(): void
    {
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
        $admin = $this->admin();
        $token = Password::broker()->createToken($admin);
        $payload = ['email' => $admin->email, 'token' => $token, 'password' => 'CustomerPassword2026', 'password_confirmation' => 'CustomerPassword2026'];
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors('password');
        $payload['password'] = $payload['password_confirmation'] = 'NewStrongAdminPassword2026!';
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->assertSame(self::SECRET, $admin->fresh()->totp_secret);
    }

    public function test_reset_with_invalid_token_does_not_disclose_admin_password_policy(): void
    {
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
        $admin = $this->admin();
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $admin->email, 'token' => 'invalid-token',
            'password' => 'CustomerPassword2026', 'password_confirmation' => 'CustomerPassword2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('token')->assertJsonMissingValidationErrors('password');
    }

    private function admin(): User
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => 'admin', 'password_hash' => self::PASSWORD]);
        $admin->forceFill(['totp_secret' => self::SECRET])->save();

        return $admin;
    }

    /** @return array{email: string, password: string} */
    private function credentials(User $admin): array
    {
        return ['email' => $admin->email, 'password' => self::PASSWORD];
    }
}
