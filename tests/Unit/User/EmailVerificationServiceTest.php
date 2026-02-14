<?php

namespace App\Tests\Unit\User;

use App\Entity\User;
use App\Tests\Support\InMemoryUserRepository;
use App\User\Service\EmailVerificationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\VerifyEmail\Exception\InvalidSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

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
            $this->buildVerifyEmailHelper(3600),
            'test',
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
            $this->buildVerifyEmailHelper(3600),
            'test',
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
            $this->buildVerifyEmailHelper(-1),
            'test',
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
            $this->buildVerifyEmailHelper(3600),
            'test',
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
            $this->buildVerifyEmailHelper(3600),
            'test',
        );

        $token = $service->requestVerification('tampered-signature@retaia.local');
        self::assertIsString($token);

        $parts = parse_url($token);
        self::assertIsArray($parts);
        $query = $parts['query'] ?? '';
        self::assertIsString($query);
        parse_str($query, $params);
        self::assertIsArray($params);
        self::assertIsString($params['signature'] ?? null);
        $params['signature'] = 'X'.substr((string) $params['signature'], 1);

        $tamperedToken = sprintf(
            '%s://%s%s?%s',
            (string) ($parts['scheme'] ?? 'http'),
            (string) ($parts['host'] ?? 'localhost'),
            (string) ($parts['path'] ?? '/'),
            http_build_query($params)
        );

        self::assertFalse($service->confirmVerification($tamperedToken));
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
            $this->buildVerifyEmailHelper(3600),
            'test',
        );

        $token = $service->requestVerification('tampered-payload@retaia.local');
        self::assertIsString($token);

        $parts = parse_url($token);
        self::assertIsArray($parts);
        $query = $parts['query'] ?? '';
        self::assertIsString($query);
        parse_str($query, $params);
        self::assertIsArray($params);
        self::assertIsString($params['id'] ?? null);
        $params['id'] = 'X'.substr((string) $params['id'], 1);

        $tamperedToken = sprintf(
            '%s://%s%s?%s',
            (string) ($parts['scheme'] ?? 'http'),
            (string) ($parts['host'] ?? 'localhost'),
            (string) ($parts['path'] ?? '/'),
            http_build_query($params)
        );

        self::assertFalse($service->confirmVerification($tamperedToken));
    }

    private function buildVerifyEmailHelper(int $lifetimeSeconds): VerifyEmailHelperInterface
    {
        return new class($lifetimeSeconds) implements VerifyEmailHelperInterface {
            public function __construct(private int $lifetimeSeconds)
            {
            }

            public function generateSignature(string $routeName, string $userId, string $userEmail, array $extraParams = []): VerifyEmailSignatureComponents
            {
                $expires = time() + $this->lifetimeSeconds;
                $normalizedEmail = mb_strtolower(trim($userEmail));
                $signature = hash('sha256', $userId.'|'.$normalizedEmail.'|'.$expires);
                $id = (string) ($extraParams['id'] ?? $userId);
                $url = sprintf(
                    'http://localhost/api/v1/auth/verify-email/confirm?id=%s&expires=%d&signature=%s&email=%s',
                    rawurlencode($id),
                    $expires,
                    rawurlencode($signature),
                    rawurlencode($normalizedEmail)
                );

                return new VerifyEmailSignatureComponents(
                    (new \DateTimeImmutable())->setTimestamp($expires),
                    $url,
                    time()
                );
            }

            public function validateEmailConfirmation(string $signedUrl, string $userId, string $userEmail): void
            {
                $parts = parse_url($signedUrl);
                $query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';
                parse_str($query, $params);

                $id = (string) ($params['id'] ?? '');
                $expires = (int) ($params['expires'] ?? 0);
                $signature = (string) ($params['signature'] ?? '');
                $email = mb_strtolower((string) ($params['email'] ?? ''));
                $normalizedEmail = mb_strtolower(trim($userEmail));
                $expected = hash('sha256', $userId.'|'.$normalizedEmail.'|'.$expires);

                if ($id !== $userId || $email !== $normalizedEmail || $expires < time() || !hash_equals($expected, $signature)) {
                    throw new InvalidSignatureException();
                }
            }

            public function validateEmailConfirmationFromRequest(Request $request, string $userId, string $userEmail): void
            {
                $this->validateEmailConfirmation($request->getUri(), $userId, $userEmail);
            }
        };
    }
}
