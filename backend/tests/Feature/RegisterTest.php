<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $compromisedHash = strtoupper(sha1('CompromisedPass123'));
        Http::fake([
            'api.pwnedpasswords.com/range/*' => Http::response(substr($compromisedHash, 5).':42', 200),
        ]);
        Mail::fake();
    }

    public function test_customer_registration_creates_profile_consent_and_sends_verification_code(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'ana@empresa.com.br')
            ->assertJsonPath('data.role', 'customer')
            ->assertJsonMissingPath('data.password_hash');

        $user = User::where('email', 'ana@empresa.com.br')->firstOrFail();

        $this->assertNull($user->email_verified_at);
        $this->assertSame('customer', $user->role);
        $this->assertTrue(Hash::check('EnergiaSegura2026', $user->password_hash));
        $this->assertDatabaseHas('customer_profiles', [
            'user_id' => $user->id,
            'company_name' => 'Empresa Exemplo Ltda.',
        ]);
        $this->assertDatabaseHas('consents', [
            'user_id' => $user->id,
            'terms_version' => '1.0',
            'ip_address' => '127.0.0.1',
        ]);

        $verificationCode = null;
        Mail::assertSent(EmailVerificationCode::class, function (EmailVerificationCode $mail) use (&$verificationCode): bool {
            $verificationCode = $mail->verificationCode;

            return $mail->hasTo('ana@empresa.com.br');
        });

        $this->assertMatchesRegularExpression('/^\d{6}$/', $verificationCode);
        $storedCode = VerificationCode::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue(Hash::check($verificationCode, $storedCode->code_hash));
        $this->assertSame(0, $storedCode->attempts);
        $this->assertTrue($storedCode->expires_at->isFuture());
    }

    public function test_registration_rejects_weak_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validPayload([
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['password']);
        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingSent();
    }

    public function test_registration_rejects_a_compromised_password(): void
    {
        $password = 'CompromisedPass123';
        $response = $this->postJson('/api/v1/auth/register', $this->validPayload([
            'password' => $password,
            'password_confirmation' => $password,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingSent();
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ana@empresa.com.br']);

        $response = $this->postJson('/api/v1/auth/register', $this->validPayload());

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['email']);
        $this->assertDatabaseCount('users', 1);
        Mail::assertNothingSent();
    }

    public function test_registration_requires_terms_acceptance(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validPayload([
            'terms_accepted' => false,
        ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['terms_accepted']);
        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('consents', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ana Souza',
            'email' => 'ana@empresa.com.br',
            'phone' => '11999999999',
            'company_name' => 'Empresa Exemplo Ltda.',
            'substation_same_address' => true,
            'terms_accepted' => true,
            'password' => 'EnergiaSegura2026',
            'password_confirmation' => 'EnergiaSegura2026',
        ], $overrides);
    }
}
