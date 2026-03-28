<?php

namespace App\Auth;

interface AuthDeviceFlowRepositoryInterface
{
    public function findByDeviceCode(string $deviceCode): ?AuthDeviceFlow;

    public function findByUserCode(string $userCode): ?AuthDeviceFlow;

    public function save(AuthDeviceFlow $flow): void;

    public function delete(string $deviceCode): void;
}
