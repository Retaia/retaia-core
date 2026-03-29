<?php

namespace App\Tests\Support;

use App\Auth\AuthMcpChallenge;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

trait AuthMcpChallengeEntityManagerTrait
{
    private function authMcpChallengeEntityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__.'/../../src/Auth'],
            true,
        );

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $config);

        $entityManager = new EntityManager($connection, $config);

        $tool = new SchemaTool($entityManager);
        $tool->createSchema([$entityManager->getClassMetadata(AuthMcpChallenge::class)]);

        return $entityManager;
    }
}
