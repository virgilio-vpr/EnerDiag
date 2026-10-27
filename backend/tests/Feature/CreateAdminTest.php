<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private const string PASSWORD = 'StrongAdminPassword2026!';

    public function test_command_creates_verified_admin_only_after_confirming_totp(): void
    {
        $this->freezeTime();
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
        $this->partialMock(TotpService::class)->shouldReceive('generateSecret')->once()->andReturn(self::SECRET);
        $code = (new TotpService)->code(self::SECRET, now()->timestamp);
        $this->enroll($code)->assertSuccessful();

        $admin = User::query()->sole();
        $this->assertSame('admin', $admin->role);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password_hash));
        $this->assertSame(self::SECRET, $admin->totp_secret);
        $this->assertNotSame(self::SECRET, DB::table('users')->value('totp_secret'));
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD, 'totp_code' => $code])
            ->assertUnprocessable();
        $this->travel(30)->seconds();
        $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email, 'password' => self::PASSWORD,
            'totp_code' => (new TotpService)->code(self::SECRET, now()->timestamp),
        ])->assertOk();
    }

    public function test_invalid_totp_does_not_leave_an_account(): void
    {
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
        $this->enroll('invalid')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_weak_password_does_not_create_account(): void
    {
        $this->artisan('admin:create', ['email' => 'admin@example.com', 'name' => 'Admin'])
            ->expectsQuestion('Senha (mínimo 14 caracteres, maiúsculas, minúsculas, números e símbolos)', 'weak')
            ->expectsQuestion('Confirme a senha', 'weak')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_confirmation_is_required(): void
    {
        $this->artisan('admin:create', ['email' => 'admin@example.com', 'name' => 'Admin'])
            ->expectsQuestion('Senha (mínimo 14 caracteres, maiúsculas, minúsculas, números e símbolos)', self::PASSWORD)
            ->expectsQuestion('Confirme a senha', 'DifferentPassword2026!')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_customer_cannot_be_promoted_or_overwritten(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $this->artisan('admin:create', ['email' => $user->email, 'name' => 'Admin'])->assertFailed();
        $this->assertSame('customer', $user->fresh()->role);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_identity_and_noninteractive_execution_do_not_create_accounts(): void
    {
        $this->artisan('admin:create', ['email' => 'invalid', 'name' => 'Admin'])->assertFailed();
        $this->artisan('admin:create', ['email' => 'admin@example.com', 'name' => 'Admin', '--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    private function enroll(string $code): PendingCommand
    {
        return $this->artisan('admin:create', ['email' => 'admin@example.com', 'name' => 'Admin'])
            ->expectsQuestion('Senha (mínimo 14 caracteres, maiúsculas, minúsculas, números e símbolos)', self::PASSWORD)
            ->expectsQuestion('Confirme a senha', self::PASSWORD)
            ->expectsQuestion('Código atual do autenticador (6 números)', $code);
    }
}
