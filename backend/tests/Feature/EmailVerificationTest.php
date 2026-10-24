<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_valid_code_verifies_email_and_removes_stored_code(): void
    {
        [$user] = $this->createPendingUser();

        $this->postJson('/api/v1/auth/verify-email', [
            'email' => $user->email,
            'code' => '123456',
        ])->assertOk()->assertJsonPath('message', 'E-mail confirmado.');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseMissing('verification_codes', ['user_id' => $user->id]);
    }

    public function test_wrong_code_increments_attempt_count(): void
    {
        [$user, $storedCode] = $this->createPendingUser();

        $this->postJson('/api/v1/auth/verify-email', [
            'email' => $user->email,
            'code' => '000000',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['code']);

        $this->assertSame(1, $storedCode->fresh()->attempts);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_expired_code_cannot_verify_email_or_consume_an_attempt(): void
    {
        [$user, $storedCode] = $this->createPendingUser();
        $storedCode->update(['expires_at' => now()->subSecond()]);

        $this->postJson('/api/v1/auth/verify-email', [
            'email' => $user->email,
            'code' => '123456',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $this->assertSame(0, $storedCode->fresh()->attempts);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_code_is_blocked_after_the_maximum_number_of_attempts(): void
    {
        [$user, $storedCode] = $this->createPendingUser();
        $maxAttempts = (int) config('enerdiag.verification_code_max_attempts');

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0 && $attempt % 3 === 0) {
                $this->travel(61)->seconds();
            }

            $this->postJson('/api/v1/auth/verify-email', [
                'email' => $user->email,
                'code' => '000000',
            ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        }

        $this->travel(61)->seconds();

        $this->postJson('/api/v1/auth/verify-email', [
            'email' => $user->email,
            'code' => '123456',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $this->assertSame($maxAttempts, $storedCode->fresh()->attempts);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_sends_a_new_hashed_code_and_is_rate_limited(): void
    {
        [$user, $storedCode] = $this->createPendingUser();
        $originalHash = $storedCode->code_hash;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/v1/auth/resend-code', [
                'email' => $user->email,
            ])->assertOk();
        }

        $this->postJson('/api/v1/auth/resend-code', [
            'email' => $user->email,
        ])->assertTooManyRequests()
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        $latestCode = VerificationCode::where('user_id', $user->id)->firstOrFail();
        $this->assertNotSame($originalHash, $latestCode->code_hash);
        $this->assertSame(0, $latestCode->attempts);
        Mail::assertSentCount(3);
        Mail::assertSent(EmailVerificationCode::class, function (EmailVerificationCode $mail) use ($user, $latestCode): bool {
            return $mail->hasTo($user->email) && Hash::check($mail->verificationCode, $latestCode->code_hash);
        });
    }

    public function test_resend_uses_the_same_response_for_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/resend-code', [
            'email' => 'naoexiste@empresa.com.br',
        ])->assertOk()->assertJsonPath(
            'message',
            'Se a conta existir e ainda não estiver confirmada, enviaremos um novo código.'
        );

        Mail::assertNothingSent();
    }

    /**
     * @return array{User, VerificationCode}
     */
    private function createPendingUser(): array
    {
        $user = User::factory()->unverified()->create();
        $storedCode = $user->verificationCodes()->create([
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(config('enerdiag.verification_code_ttl_minutes')),
            'attempts' => 0,
        ]);

        return [$user, $storedCode];
    }
}
