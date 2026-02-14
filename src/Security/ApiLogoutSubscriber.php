<?php

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class ApiLogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
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

        $event->setResponse(new JsonResponse(['authenticated' => false], Response::HTTP_OK));
    }
}
