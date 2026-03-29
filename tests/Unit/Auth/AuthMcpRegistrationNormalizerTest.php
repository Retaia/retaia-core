<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthMcpRegistrationNormalizer;
use PHPUnit\Framework\TestCase;

final class AuthMcpRegistrationNormalizerTest extends TestCase
{
    public function testNormalizeFingerprintStripsWhitespaceAndUppercases(): void
    {
        $normalizer = new AuthMcpRegistrationNormalizer();

        self::assertSame('0123456789ABCDEF0123456789ABCDEF01234567', $normalizer->normalizeFingerprint(" 0123456789abcdef0123456789abcdef01234567 \n"));
    }

    public function testNormalizePublicKeyRejectsBlankInput(): void
    {
        $normalizer = new AuthMcpRegistrationNormalizer();

        self::assertNull($normalizer->normalizePublicKey(" \n "));
    }
}
