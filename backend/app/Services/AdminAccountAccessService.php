<?php

namespace App\Services;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

final class AdminAccountAccessService
{
    public function updatePassword(User $user, #[\SensitiveParameter] string $password, ?int $currentTokenId): void
    {
        DB::transaction(function () use ($user, $password, $currentTokenId): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->forceFill(['password_hash' => $password])->save();
            Password::deleteToken($locked);
            $this->revokeOtherSessions($locked, $currentTokenId);
        });
        Cache::forget($this->emailChangeKey($user));
    }

    public function requestEmailChange(User $user, string $email): void
    {
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(config('enerdiag.verification_code_ttl_minutes'));
        Mail::to($email)->send(new EmailVerificationCode($code));
        Cache::put($this->emailChangeKey($user), [
            'email' => $email,
            'original_email' => $user->email,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => $expiresAt->timestamp,
        ], $expiresAt);
    }

    public function confirmEmailChange(User $user, #[\SensitiveParameter] string $code, ?int $currentTokenId): string
    {
        $key = $this->emailChangeKey($user);
        $email = Cache::lock($key.':lock', 10)->block(2, function () use ($key, $user, $code, $currentTokenId): ?string {
            $pending = Cache::get($key);
            if (! is_array($pending) || $pending['expires_at'] <= now()->timestamp
                || $pending['attempts'] >= config('enerdiag.verification_code_max_attempts')) {
                return null;
            }
            if (! Hash::check($code, $pending['code_hash'])) {
                $pending['attempts']++;
                Cache::put($key, $pending, now()->setTimestamp($pending['expires_at']));

                return null;
            }

            $email = DB::transaction(function () use ($user, $pending, $currentTokenId): ?string {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                if ($locked->email !== $pending['original_email']) {
                    return null;
                }
                if (User::query()->where('email', $pending['email'])->whereKeyNot($locked->id)->exists()) {
                    throw ValidationException::withMessages(['email' => 'Este e-mail já está cadastrado. Solicite um novo código para outro endereço.']);
                }
                Password::deleteToken($locked);
                $locked->forceFill(['email' => $pending['email'], 'email_verified_at' => now()])->save();
                Password::deleteToken($locked);
                $this->revokeOtherSessions($locked, $currentTokenId);

                return $locked->email;
            });
            Cache::forget($key);

            return $email;
        });

        if ($email === null) {
            throw ValidationException::withMessages(['code' => 'Código inválido, expirado ou com limite de tentativas excedido. Solicite outro código.']);
        }

        return $email;
    }

    private function emailChangeKey(User $user): string
    {
        return 'admin-email-change:'.$user->id;
    }

    private function revokeOtherSessions(User $user, ?int $currentTokenId): void
    {
        $user->tokens()->when($currentTokenId !== null, fn ($query) => $query->whereKeyNot($currentTokenId))->delete();
    }
}
