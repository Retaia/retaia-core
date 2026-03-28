<?php

namespace App\Auth;

interface TechnicalAccessTokenRepositoryInterface
{
    public function findByClientId(string $clientId): ?TechnicalAccessTokenRecord;

    public function findByAccessToken(string $accessToken): ?TechnicalAccessTokenRecord;

    public function save(TechnicalAccessTokenRecord $record): void;

    public function deleteByClientId(string $clientId): void;
}
