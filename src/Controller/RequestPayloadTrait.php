<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

trait RequestPayloadTrait
{
    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request, bool $fallbackToRequestBag = false): array
    {
        if ($request->getContent() === '') {
            return $fallbackToRequestBag ? $request->request->all() : [];
        }

        $decoded = json_decode($request->getContent(), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return $fallbackToRequestBag ? $request->request->all() : [];
    }
}

