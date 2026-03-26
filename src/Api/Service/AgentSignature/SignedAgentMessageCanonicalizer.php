<?php

namespace App\Api\Service\AgentSignature;

use Symfony\Component\HttpFoundation\Request;

final class SignedAgentMessageCanonicalizer
{
    public function canonicalize(Request $request): string
    {
        $path = $request->getPathInfo();
        $query = (string) $request->getQueryString();
        if ($query !== '') {
            $path .= '?'.$query;
        }

        $body = (string) $request->getContent();

        return implode("\n", [
            strtoupper($request->getMethod()),
            $path,
            hash('sha256', $body),
            trim((string) $request->headers->get('X-Retaia-Agent-Id', '')),
            trim((string) $request->headers->get('X-Retaia-OpenPGP-Fingerprint', '')),
            trim((string) $request->headers->get('X-Retaia-Signature-Timestamp', '')),
            trim((string) $request->headers->get('X-Retaia-Signature-Nonce', '')),
        ]);
    }
}
