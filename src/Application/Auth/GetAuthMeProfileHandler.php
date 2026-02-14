<?php

namespace App\Application\Auth;

final class GetAuthMeProfileHandler
{
    /**
     * @param array<int, string> $roles
     */
    public function handle(string $id, string $email, array $roles): GetAuthMeProfileResult
    {
        return new GetAuthMeProfileResult($id, $email, $roles);
    }
}
