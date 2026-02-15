<?php

namespace App\Tests\Unit\User;

use App\User\Service\TwoFactorSecretCipher;
use PHPUnit\Framework\TestCase;

final class TwoFactorSecretCipherTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $cipher = new TwoFactorSecretCipher(
            'v1:BR/S2JUbOPhtWvvGSl7mme/p85UkTI3dxqWyj4eBJhs=,v2:WuCdN8gv+LrJgjvD+7nNe1DxvP/pbA4VOMdLhtGa1LU=',
            'v2'
        );

        $encrypted = $cipher->encrypt('SECRET123');
        self::assertStringStartsWith('v2:', $encrypted);
        self::assertSame('SECRET123', $cipher->decrypt($encrypted));
        self::assertFalse($cipher->needsRotation($encrypted));
    }

    public function testNeedsRotationWhenPayloadUsesOldVersion(): void
    {
        $oldCipher = new TwoFactorSecretCipher(
            'v1:BR/S2JUbOPhtWvvGSl7mme/p85UkTI3dxqWyj4eBJhs=,v2:WuCdN8gv+LrJgjvD+7nNe1DxvP/pbA4VOMdLhtGa1LU=',
            'v1'
        );
        $newCipher = new TwoFactorSecretCipher(
            'v1:BR/S2JUbOPhtWvvGSl7mme/p85UkTI3dxqWyj4eBJhs=,v2:WuCdN8gv+LrJgjvD+7nNe1DxvP/pbA4VOMdLhtGa1LU=',
            'v2'
        );

        $encryptedV1 = $oldCipher->encrypt('SECRET123');
        self::assertStringStartsWith('v1:', $encryptedV1);
        self::assertSame('SECRET123', $newCipher->decrypt($encryptedV1));
        self::assertTrue($newCipher->needsRotation($encryptedV1));
    }
}
