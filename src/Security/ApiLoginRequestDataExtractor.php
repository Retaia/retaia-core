<?php

namespace App\Security;

use App\Domain\AuthClient\ClientKind;
use Symfony\Component\HttpFoundation\Request;

final class ApiLoginRequestDataExtractor
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array{email:string,password:string}
     */
    public function credentials(Request $request): array
    {
        $payload = $this->payload($request);

        return [
            'email' => trim((string) ($payload['email'] ?? '')),
            'password' => (string) ($payload['password'] ?? ''),
        ];
    }

    /**
     * @return array{otp_code:string,recovery_code:string}
     */
    public function secondFactor(Request $request): array
    {
        $payload = $this->payload($request);

        return [
            'otp_code' => trim((string) ($payload['otp_code'] ?? '')),
            'recovery_code' => trim((string) ($payload['recovery_code'] ?? '')),
        ];
    }

    /**
     * @return array{client_id:string,client_kind:string}
     */
    public function client(Request $request): array
    {
        $payload = $this->payload($request);
        $clientId = trim((string) ($payload['client_id'] ?? 'interactive-default'));
        if ($clientId === '') {
            $clientId = 'interactive-default';
        }

        return [
            'client_id' => $clientId,
            'client_kind' => trim((string) ($payload['client_kind'] ?? ClientKind::UI_WEB)),
        ];
    }

    public function emailHash(Request $request): string
    {
        $payload = $this->payload($request);

        return hash('sha256', mb_strtolower(trim((string) ($payload['email'] ?? ''))));
    }
}
