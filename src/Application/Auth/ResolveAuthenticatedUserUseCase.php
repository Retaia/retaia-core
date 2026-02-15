<?php

namespace App\Application\Auth;

interface ResolveAuthenticatedUserUseCase
{
    public function handle(): ResolveAuthenticatedUserResult;
}
