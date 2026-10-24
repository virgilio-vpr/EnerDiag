<?php

namespace App\Services;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class EmailVerificationService
{
    /**
     * Store a new numeric email verification code and return its plaintext value for delivery.
     */
    public function storeCode(User $user, ?string $code = null): string
    {
        $code ??= (string) random_int(100000, 999999);

        $user->verificationCodes()->delete();
        $user->verificationCodes()->create([
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(config('enerdiag.verification_code_ttl_minutes')),
            'attempts' => 0,
        ]);

        return $code;
    }

    /**
     * Deliver a verification code to the account email address.
     */
    public function sendCode(User $user, string $code): void
    {
        Mail::to($user->email)->send(new EmailVerificationCode($code));
    }

    /**
     * Verify the current code for an account, counting every incorrect attempt.
     */
    public function verify(string $email, string $code): void
    {
        $isVerified = DB::transaction(function () use ($email, $code): bool {
            $user = User::query()->where('email', $email)->first();

            if (! $user || $user->email_verified_at) {
                return false;
            }

            $verificationCode = $user->verificationCodes()->latest('id')->lockForUpdate()->first();
            $maxAttempts = (int) config('enerdiag.verification_code_max_attempts');

            if (! $verificationCode || $verificationCode->expires_at->isPast() || $verificationCode->attempts >= $maxAttempts) {
                return false;
            }

            if (! Hash::check($code, $verificationCode->code_hash)) {
                $verificationCode->increment('attempts');

                return false;
            }

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->verificationCodes()->delete();

            return true;
        });

        if (! $isVerified) {
            throw ValidationException::withMessages([
                'code' => 'Código inválido, expirado ou com limite de tentativas excedido.',
            ]);
        }
    }

    /**
     * Resend a code when the address belongs to an unverified account.
     */
    public function resend(string $email): void
    {
        $user = User::query()
            ->where('email', $email)
            ->whereNull('email_verified_at')
            ->first();

        if (! $user) {
            return;
        }

        $code = DB::transaction(fn (): string => $this->storeCode($user));

        $this->sendCode($user, $code);
    }
}
