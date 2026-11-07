<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use App\Services\AdminAccountAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    private const string CURRENT_PASSWORD = 'OriginalAdminPassword2026!';

    private const string NEW_PASSWORD = 'UpdatedAdminPassword2026!';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
    }

    public function test_access_changes_require_authentication_and_admin_permission(): void
    {
        $this->putJson('/api/v1/me/password')->assertUnauthorized();
        $this->postJson('/api/v1/me/email-change')->assertUnauthorized();
        $this->postJson('/api/v1/me/email-change/confirm')->assertUnauthorized();
        $customer = User::factory()->create(['role' => 'customer']);
        $token = $customer->createToken('customer')->plainTextToken;
        $this->withToken($token)->putJson('/api/v1/me/password')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/me/email-change')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/me/email-change/confirm')->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_current_password_is_required_for_password_and_email_changes(): void
    {
        [$admin, $token] = $this->admin();
        $originalHash = $admin->password_hash;
        $this->withToken($token)->putJson('/api/v1/me/password', [
            'current_password' => 'IncorrectPassword', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->withToken($token)->postJson('/api/v1/me/email-change', ['current_password' => 'IncorrectPassword', 'email' => 'new-admin@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame($originalHash, $admin->fresh()->password_hash);
        Mail::assertNothingSent();
    }

    public function test_new_password_requires_confirmation_and_admin_strength(): void
    {
        [, $token] = $this->admin();
        $this->withToken($token)->putJson('/api/v1/me/password', [
            'current_password' => self::CURRENT_PASSWORD, 'password' => 'short', 'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_admin_changes_password_preserving_current_session_and_totp(): void
    {
        [$admin, $token] = $this->admin();
        $admin->forceFill(['totp_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'])->save();
        $otherSession = $admin->createToken('other-session')->accessToken;
        Password::createToken($admin);
        $this->withToken($token)->putJson('/api/v1/me/password', [
            'current_password' => self::CURRENT_PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->fresh()->password_hash));
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $admin->fresh()->totp_secret);
        $this->assertSame(1, $admin->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherSession->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $admin->email]);
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    }

    public function test_email_changes_only_after_the_new_address_is_verified(): void
    {
        [$admin, $token] = $this->admin();
        $oldEmail = $admin->email;
        $this->withToken($token)->postJson('/api/v1/me/email-change', $this->emailRequest())->assertOk()->assertJsonMissingPath('code');
        $this->assertSame($oldEmail, $admin->fresh()->email);
        Mail::assertSent(EmailVerificationCode::class, fn ($mail): bool => $mail->hasTo('new-admin@example.test'));
        $code = Mail::sent(EmailVerificationCode::class)->last()->verificationCode;
        $otherSession = $admin->createToken('other-session')->accessToken;
        Password::createToken($admin);
        $this->withToken($token)->postJson('/api/v1/me/email-change/confirm', ['code' => $code])
            ->assertOk()->assertJsonPath('email', 'new-admin@example.test');
        $this->assertSame('new-admin@example.test', $admin->fresh()->email);
        $this->assertNotNull($admin->fresh()->email_verified_at);
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame(1, $admin->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherSession->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
        $this->withToken($token)->postJson('/api/v1/me/email-change/confirm', ['code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_invalid_expired_or_exhausted_codes_do_not_change_the_login(): void
    {
        [$admin, $token] = $this->admin();
        $email = $admin->email;
        $this->withToken($token)->postJson('/api/v1/me/email-change', $this->emailRequest())->assertOk();
        $code = Mail::sent(EmailVerificationCode::class)->last()->verificationCode;
        for ($attempt = 0; $attempt < config('enerdiag.verification_code_max_attempts'); $attempt++) {
            try {
                app(AdminAccountAccessService::class)->confirmEmailChange($admin, '000000', $admin->tokens()->first()->id);
                $this->fail('An invalid code was accepted');
            } catch (ValidationException) {
                $this->assertSame($email, $admin->fresh()->email);
            }
        }
        $this->withToken($token)->postJson('/api/v1/me/email-change/confirm', ['code' => $code])->assertUnprocessable();
        Cache::forget('admin-email-change:'.$admin->id);
        app(AdminAccountAccessService::class)->requestEmailChange($admin, 'new-admin@example.test');
        $code = Mail::sent(EmailVerificationCode::class)->last()->verificationCode;
        $this->travel(16)->minutes();
        $this->withToken($token)->postJson('/api/v1/me/email-change/confirm', ['code' => $code])->assertUnprocessable();
        $this->assertSame($email, $admin->fresh()->email);
    }

    public function test_duplicate_email_is_rejected_without_replacing_the_current_login(): void
    {
        [, $token] = $this->admin();
        User::factory()->create(['email' => 'new-admin@example.test']);
        $this->withToken($token)->postJson('/api/v1/me/email-change', $this->emailRequest())->assertUnprocessable()->assertJsonValidationErrors('email');
        Mail::assertNothingSent();
    }

    public function test_email_claimed_after_request_is_checked_again_at_confirmation(): void
    {
        [$admin, $token] = $this->admin();
        $this->withToken($token)->postJson('/api/v1/me/email-change', $this->emailRequest())->assertOk();
        $code = Mail::sent(EmailVerificationCode::class)->last()->verificationCode;
        User::factory()->create(['email' => 'new-admin@example.test']);
        $this->withToken($token)->postJson('/api/v1/me/email-change/confirm', ['code' => $code])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNotSame('new-admin@example.test', $admin->fresh()->email);
    }

    /** @return array{User, string} */
    private function admin(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'password_hash' => Hash::make(self::CURRENT_PASSWORD)]);

        return [$admin, $admin->createToken('admin-access-test')->plainTextToken];
    }

    /** @return array<string, string> */
    private function emailRequest(): array
    {
        return ['current_password' => self::CURRENT_PASSWORD, 'email' => 'new-admin@example.test'];
    }
}
