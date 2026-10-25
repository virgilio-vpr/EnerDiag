<?php

namespace Tests\Unit;

use App\Services\TotpService;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    private const string SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_it_matches_the_six_digit_suffix_of_rfc_6238_sha1_vectors(): void
    {
        $service = new TotpService;
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'] as $timestamp => $code) {
            $this->assertSame($code, $service->code(self::SECRET, $timestamp));
        }
    }

    public function test_validation_allows_only_one_adjacent_time_step_and_six_ascii_digits(): void
    {
        $service = new TotpService;
        $timestamp = 1234567890;
        foreach ([-30, 0, 30] as $offset) {
            $code = $service->code(self::SECRET, $timestamp + $offset);
            $this->assertSame(intdiv($timestamp + $offset, 30), $service->matchingStep(self::SECRET, $code, $timestamp));
        }
        $this->assertNull($service->matchingStep(self::SECRET, $service->code(self::SECRET, $timestamp - 60), $timestamp));
        foreach (['12345', '1234567', "005924\n", 'abcdef'] as $code) {
            $this->assertNull($service->matchingStep(self::SECRET, $code, $timestamp));
        }
    }

    public function test_provisioning_uses_a_secure_random_secret_and_an_encoded_account_label(): void
    {
        $service = new TotpService;
        $secret = $service->generateSecret();
        $this->assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $secret);
        $uri = $service->provisioningUri('admin+test@example.com', $secret);
        $this->assertStringContainsString(rawurlencode('EnerDiag:admin+test@example.com'), $uri);
        parse_str(parse_url($uri, PHP_URL_QUERY), $query);
        $this->assertSame($secret, $query['secret']);
        $this->assertSame('6', $query['digits']);
        $this->assertSame('30', $query['period']);
    }
}
