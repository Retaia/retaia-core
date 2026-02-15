<?php

namespace App\Security;

use App\Auth\UserAccessTokenService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class ApiLogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private UserAccessTokenService $userAccessTokenService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLogout(LogoutEvent $event): void
    {
        if ($event->getRequest()->attributes->get('_route') !== 'api_auth_logout') {
            return;
        }

        $token = $event->getToken();
        $this->logger->info('auth.logout.completed', [
            'user_identifier' => $token?->getUserIdentifier(),
        ]);
        $this->logger->info('auth.logout', [
            'user_identifier' => $token?->getUserIdentifier(),
        ]);

        $authorization = (string) $event->getRequest()->headers->get('Authorization', '');
        if (str_starts_with($authorization, 'Bearer ')) {
            $this->userAccessTokenService->revoke(trim(substr($authorization, 7)));
        }

        $event->setResponse(new JsonResponse(['authenticated' => false], Response::HTTP_OK));
    }
}
