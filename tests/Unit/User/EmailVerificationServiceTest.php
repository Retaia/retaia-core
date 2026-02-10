<?php

namespace App\Tests\Unit\User;

use App\Entity\User;
use App\Tests\Support\InMemoryUserRepository;
use App\User\Service\EmailVerificationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EmailVerificationServiceTest extends TestCase
{
    public function testRequestAndConfirmVerificationForUnverifiedUser(): void
    {
        $users = new InMemoryUserRepository();
        $users->save(new User(
            'testpending0000001',
            'pending@retaia.local',
            password_hash('change-me', PASSWORD_DEFAULT),
            ['ROLE_USER'],
            false,
        ));

        $service = new EmailVerificationService(
            $users,
            new NullLogger(),
            'test',
            'secret-key',
            3600,
        );

        $token = $service->requestVerification('pending@retaia.local');

        self::assertIsString($token);
        self::assertNotSame('', $token);
        self::assertTrue($service->confirmVerification($token));
        self::assertTrue($users->findByEmail('pending@retaia.local')?->isEmailVerified() ?? false);
    }

    public function testConfirmRejectsInvalidToken(): void
    {
        $users = new InMemoryUserRepository();
        $users->seedDefaultAdmin();
        $service = new EmailVerificationService(
            $users,
            new NullLogger(),
            'test',
            'secret-key',
            3600,
        );

        self::assertFalse($service->confirmVerification('not-a-valid-token'));
    }

    public function testConfirmRejectsExpiredToken(): void
    {
        $users = new InMemoryUserRepository();
        $users->save(new User(
            'testpending0000002',
            'expired@retaia.local',
            password_hash('change-me', PASSWORD_DEFAULT),
            ['ROLE_USER'],
            false,
        ));

        $service = new EmailVerificationService(
            $users,
            new NullLogger(),
            'test',
            'secret-key',
            -1,
        );

        $token = $service->requestVerification('expired@retaia.local');
        self::assertIsString($token);
        self::assertFalse($service->confirmVerification($token));
    }

    public function testForceVerifyByEmail(): void
    {
        $users = new InMemoryUserRepository();
        $users->save(new User(
            'testpending0000003',
            'ops@retaia.local',
            password_hash('change-me', PASSWORD_DEFAULT),
            ['ROLE_USER'],
            false,
        ));

        $service = new EmailVerificationService(
            $users,
            new NullLogger(),
            'test',
            'secret-key',
            3600,
        );

        self::assertTrue($service->forceVerifyByEmail('ops@retaia.local', 'admin-actor-1'));
        self::assertTrue($users->findByEmail('ops@retaia.local')?->isEmailVerified() ?? false);
        self::assertFalse($service->forceVerifyByEmail('missing@retaia.local', 'admin-actor-1'));
    }

    public function testConfirmRejectsTokenWithTamperedSignature(): void
    {
        $users = new InMemoryUserRepository();
        $users->save(new User(
            'testpending0000004',
            'tampered-signature@retaia.local',
            password_hash('change-me', PASSWORD_DEFAULT),
            ['ROLE_USER'],
            false,
        ));

        $service = new EmailVerificationService(
            $users,
            new NullLogger(),
            'test',
            'secret-key',
            3600,
        );

        $token = $service->requestVerification('tampered-signature@retaia.local');
        self::assertIsString($token);

        [$payload, $signature] = explode('.', $token, 2);
        $tamperedSignature = substr($signature, 0, -1).('a' === substr($signature, -1) ? 'b' : 'a');

        self::assertFalse($service->confirmVerification($payload.'.'.$tamperedSignature));
    }

    public function testConfirmRejectsTokenWithTamperedPayload(): void
    {
        $users = new InMemoryUserRepository();
        $users->save(new User(
            'testpending0000005',
            'tampered-payload@retaia.local',
            password_hash('change-me', PASSWORD_DEFAULT),
            ['ROLE_USER'],
            false,
        ));

        $service = new EmailVerificationService(
            $users,
            new NullLogger(),
            'test',
            'secret-key',
            3600,
        );

        $token = $service->requestVerification('tampered-payload@retaia.local');
        self::assertIsString($token);

        [$payload, $signature] = explode('.', $token, 2);
        $tamperedPayload = 'X'.substr($payload, 1);

        self::assertFalse($service->confirmVerification($tamperedPayload.'.'.$signature));
    }
}
