<?php

namespace App\Security;

use App\Auth\UserAccessTokenService;
use App\Controller\Api\ApiErrorResponseFactory;
use App\Domain\AuthClient\ClientKind;
use App\Entity\User;
use App\User\Service\TwoFactorService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiLoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const LOGIN_ROUTE = 'api_auth_login';

    private ApiLoginRequestDataExtractor $requestDataExtractor;
    private ApiLoginSecondFactorChallengeResponder $secondFactorChallengeResponder;

    public function __construct(
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private TwoFactorService $twoFactorService,
        private UserAccessTokenService $userAccessTokenService,
        #[Autowire(service: 'limiter.auth_2fa_challenge')]
        RateLimiterFactory $twoFactorChallengeRateLimiter,
        ?ApiLoginRequestDataExtractor $requestDataExtractor = null,
        ?ApiLoginSecondFactorAttemptLimiter $secondFactorAttemptLimiter = null,
        ?ApiLoginSecondFactorChallengeResponder $secondFactorChallengeResponder = null,
    ) {
        $this->requestDataExtractor = $requestDataExtractor ?? new ApiLoginRequestDataExtractor();
        $attemptLimiter = $secondFactorAttemptLimiter ?? new ApiLoginSecondFactorAttemptLimiter($twoFactorChallengeRateLimiter, $translator);
        $this->secondFactorChallengeResponder = $secondFactorChallengeResponder ?? new ApiLoginSecondFactorChallengeResponder(
            $twoFactorService,
            $attemptLimiter,
            $translator,
            $this->requestDataExtractor,
        );
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === self::LOGIN_ROUTE
            && $request->isMethod(Request::METHOD_POST);
    }

    public function authenticate(Request $request): Passport
    {
        $credentials = $this->requestDataExtractor->credentials($request);
        $email = $credentials['email'];
        $password = $credentials['password'];

        if ($email === '' || $password === '') {
            $this->logger->info('auth.login.failed', [
                'reason' => 'validation',
                'email_hash' => $this->requestDataExtractor->emailHash($request),
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

            return ApiErrorResponseFactory::create(
                'UNAUTHORIZED',
                $this->translator->trans('auth.error.invalid_credentials'),
                Response::HTTP_UNAUTHORIZED
            );
        }

        if ($user instanceof User) {
            $secondFactorResponse = $this->secondFactorChallengeResponder->handle($request, $user);
            if ($secondFactorResponse instanceof JsonResponse) {
                return $secondFactorResponse;
            }
        }

        if (!$user instanceof User) {
            return ApiErrorResponseFactory::create(
                'UNAUTHORIZED',
                $this->translator->trans('auth.error.invalid_credentials'),
                Response::HTTP_UNAUTHORIZED
            );
        }

        $client = $this->requestDataExtractor->client($request);
        $clientId = $client['client_id'];
        $clientKind = $client['client_kind'];
        if (!ClientKind::isInteractive($clientKind)) {
            return ApiErrorResponseFactory::create(
                'VALIDATION_FAILED',
                $this->translator->trans('auth.error.client_kind_required'),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        $accessToken = $this->userAccessTokenService->issue($user, $clientId, $clientKind);

        $this->logger->info('auth.login.succeeded', [
            'user_identifier' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
            'client_id' => $clientId,
            'client_kind' => $clientKind,
        ]);
        $this->logger->info('auth.login.success', [
            'user_identifier' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ]);

        return new JsonResponse($accessToken, Response::HTTP_OK);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $emailHash = $this->requestDataExtractor->emailHash($request);

        if ($exception->getMessageKey() === 'VALIDATION_FAILED') {
            return ApiErrorResponseFactory::create(
                'VALIDATION_FAILED',
                $this->translator->trans('auth.error.email_password_required'),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($exception->getMessageKey() === 'EMAIL_NOT_VERIFIED') {
            return ApiErrorResponseFactory::create(
                'EMAIL_NOT_VERIFIED',
                $this->translator->trans('auth.error.email_not_verified'),
                Response::HTTP_FORBIDDEN
            );
        }

        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $minutes = $exception->getMessageData()['%minutes%'] ?? null;
            $this->logger->warning('auth.login.throttled', [
                'email_hash' => $emailHash,
                'retry_in_minutes' => is_int($minutes) ? $minutes : null,
            ]);

            return ApiErrorResponseFactory::createWithFields(
                'TOO_MANY_ATTEMPTS',
                $this->translator->trans('auth.error.too_many_login_attempts'),
                Response::HTTP_TOO_MANY_REQUESTS,
                is_int($minutes) && $minutes > 0 ? ['retry_in_minutes' => $minutes] : []
            );
        }

        $this->logger->info('auth.login.failed', [
            'reason' => 'invalid_credentials',
            'email_hash' => $emailHash,
        ]);

        return ApiErrorResponseFactory::create(
            'UNAUTHORIZED',
            $this->translator->trans('auth.error.invalid_credentials'),
            Response::HTTP_UNAUTHORIZED
        );
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ApiErrorResponseFactory::create(
            'UNAUTHORIZED',
            $this->translator->trans('auth.error.authentication_required'),
            Response::HTTP_UNAUTHORIZED
        );
    }
}
