<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TotpService
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::ALPHABET[bindec($chunk)];
        }

        return $secret;
    }

    /** RFC 6238, SHA-1, six digits, thirty-second time steps. */
    public function code(#[\SensitiveParameter] string $secret, int $timestamp): string
    {
        if ($timestamp < 0 || ! preg_match('/\A[A-Z2-7]{32}\z/', $secret)) {
            throw new InvalidArgumentException('Invalid TOTP parameters.');
        }

        $bits = '';
        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $character)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            $key .= chr(bindec($byte));
        }

        $step = intdiv($timestamp, 30);
        $hash = hash_hmac('sha1', pack('N2', intdiv($step, 4294967296), $step % 4294967296), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function matchingStep(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, int $timestamp): ?int
    {
        if (! preg_match('/\A[0-9]{6}\z/', $code)) {
            return null;
        }

        $currentStep = intdiv($timestamp, 30);
        foreach ([$currentStep, $currentStep - 1, $currentStep + 1] as $step) {
            if ($step >= 0 && hash_equals($this->code($secret, $step * 30), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function consume(User $user, #[\SensitiveParameter] string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($locked->role !== 'admin' || $locked->totp_secret === null) {
                return false;
            }
            $step = $this->matchingStep($locked->totp_secret, $code, now()->timestamp);
            if ($step === null || ($locked->totp_last_used_step !== null && $step <= $locked->totp_last_used_step)) {
                return false;
            }
            $locked->forceFill(['totp_last_used_step' => $step])->save();

            return true;
        });
    }

    public function provisioningUri(string $email, #[\SensitiveParameter] string $secret): string
    {
        return 'otpauth://totp/'.rawurlencode('EnerDiag:'.$email).'?'.http_build_query([
            'secret' => $secret, 'issuer' => 'EnerDiag', 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
