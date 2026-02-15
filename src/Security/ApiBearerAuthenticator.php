<?php

namespace App\Security;

use App\Auth\ClientAccessTokenResolver;
use App\Auth\UserAccessTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiBearerAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private UserAccessTokenService $userAccessTokenService,
        private ClientAccessTokenResolver $clientAccessTokenResolver,
        private TranslatorInterface $translator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if (!str_starts_with($request->getPathInfo(), '/api/v1/')) {
            return false;
        }

        return str_starts_with((string) $request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $rawHeader = (string) $request->headers->get('Authorization', '');
        $accessToken = trim(substr($rawHeader, 7));
        if ($accessToken === '') {
            throw new CustomUserMessageAuthenticationException('UNAUTHORIZED');
        }

        $userPayload = $this->userAccessTokenService->validate($accessToken);
        if (is_array($userPayload)) {
            return new SelfValidatingPassport(new UserBadge((string) $userPayload['email']));
        }

        $clientPayload = $this->clientAccessTokenResolver->resolve($accessToken);
        if (is_array($clientPayload)) {
            $clientId = (string) $clientPayload['client_id'];
            $clientKind = (string) $clientPayload['client_kind'];

            return new SelfValidatingPassport(
                new UserBadge(
                    'client:'.$clientId,
                    static fn (): ApiClientPrincipal => new ApiClientPrincipal($clientId, $clientKind)
                )
            );
        }

        throw new CustomUserMessageAuthenticationException('UNAUTHORIZED');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
            Response::HTTP_UNAUTHORIZED
        );
    }
}
