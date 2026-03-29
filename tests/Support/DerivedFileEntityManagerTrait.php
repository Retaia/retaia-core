<?php

namespace App\Tests\Support;

use App\Derived\DerivedFile;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

trait DerivedFileEntityManagerTrait
{
    private function derivedFileEntityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__.'/../../src/Derived'],
            true,
        );

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $config);

        $entityManager = new EntityManager($connection, $config);

        $tool = new SchemaTool($entityManager);
        $tool->createSchema([$entityManager->getClassMetadata(DerivedFile::class)]);

        return $entityManager;
    }
}
