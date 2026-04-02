<?php

namespace App\Tests\Unit\Security;

use App\Security\ApiLoginRequestDataExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiLoginRequestDataExtractorTest extends TestCase
{
    public function testCredentialsAndEmailHashNormalizePayload(): void
    {
        $extractor = new ApiLoginRequestDataExtractor();
        $request = Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{"email":" Admin@Retaia.local ","password":"change-me"}');

        self::assertSame(['email' => 'Admin@Retaia.local', 'password' => 'change-me'], $extractor->credentials($request));
        self::assertSame(hash('sha256', 'admin@retaia.local'), $extractor->emailHash($request));
    }

    public function testClientAndSecondFactorFallbacks(): void
    {
        $extractor = new ApiLoginRequestDataExtractor();
        $request = Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{"client_id":" ","client_kind":"AGENT","otp_code":" 123456 ","recovery_code":" rc "}');

        self::assertSame(['client_id' => 'interactive-default', 'client_kind' => 'AGENT'], $extractor->client($request));
        self::assertSame(['otp_code' => '123456', 'recovery_code' => 'rc'], $extractor->secondFactor($request));
    }
}
