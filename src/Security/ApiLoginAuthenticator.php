<?php

namespace App\Security;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class ApiLoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const LOGIN_ROUTE = 'api_auth_login';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === self::LOGIN_ROUTE
            && $request->isMethod(Request::METHOD_POST);
    }

    public function authenticate(Request $request): Passport
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->logger->info('auth.login.failed', [
                'reason' => 'validation',
                'email_hash' => $this->hashEmail($email),
            ]);

            throw new CustomUserMessageAuthenticationException('VALIDATION_FAILED');
        }

        return new Passport(new UserBadge($email), new PasswordCredentials($password));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            $this->logger->warning('auth.login.failed', [
                'reason' => 'invalid_user_type',
            ]);

            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => 'Invalid credentials'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $this->logger->info('auth.login.succeeded', [
            'user_identifier' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ]);

        return new JsonResponse(
            [
                'authenticated' => true,
                'user' => $this->normalizeUser($user),
            ],
            Response::HTTP_OK
        );
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $emailHash = $this->emailHashFromRequest($request);

        if ($exception->getMessageKey() === 'VALIDATION_FAILED') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => 'email and password are required'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $minutes = $exception->getMessageData()['%minutes%'] ?? null;
            $response = ['code' => 'TOO_MANY_ATTEMPTS', 'message' => 'Too many login attempts'];
            if (is_int($minutes) && $minutes > 0) {
                $response['retry_in_minutes'] = $minutes;
            }

            $this->logger->warning('auth.login.throttled', [
                'email_hash' => $emailHash,
                'retry_in_minutes' => is_int($minutes) ? $minutes : null,
            ]);

            return new JsonResponse($response, Response::HTTP_TOO_MANY_REQUESTS);
        }

        $this->logger->info('auth.login.failed', [
            'reason' => 'invalid_credentials',
            'email_hash' => $emailHash,
        ]);

        return new JsonResponse(
            ['code' => 'UNAUTHORIZED', 'message' => 'Invalid credentials'],
            Response::HTTP_UNAUTHORIZED
        );
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            ['code' => 'UNAUTHORIZED', 'message' => 'Authentication required'],
            Response::HTTP_UNAUTHORIZED
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeUser(UserInterface $user): array
    {
        if ($user instanceof User) {
            return [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ];
        }

        return [
            'id' => null,
            'email' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ];
    }

    private function emailHashFromRequest(Request $request): string
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->hashEmail('');
        }

        return $this->hashEmail((string) ($payload['email'] ?? ''));
    }

    private function hashEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }
}
