<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthDeviceFlow;
use App\Auth\AuthDeviceFlowRepository;
use App\Tests\Support\AuthDeviceFlowEntityManagerTrait;
use PHPUnit\Framework\TestCase;

final class AuthDeviceFlowRepositoryTest extends TestCase
{
    use AuthDeviceFlowEntityManagerTrait;

    public function testSaveAndQueryByDeviceCodeAndUserCode(): void
    {
        $repository = new AuthDeviceFlowRepository($this->authDeviceFlowEntityManager());
        $flow = new AuthDeviceFlow('dc_1', 'ABCD1234', 'AGENT', 'PENDING', 10, 20, 5, 0, null, null);

        $repository->save($flow);

        self::assertSame('dc_1', $repository->findByDeviceCode('dc_1')?->deviceCode);
        self::assertSame('ABCD1234', $repository->findByUserCode('abcd1234')?->userCode);

        $repository->delete('dc_1');
        self::assertNull($repository->findByDeviceCode('dc_1'));
    }
}
