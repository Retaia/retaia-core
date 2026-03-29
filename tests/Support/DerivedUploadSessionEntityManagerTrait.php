<?php

namespace App\Tests\Support;

use App\Derived\DerivedUploadSession;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

trait DerivedUploadSessionEntityManagerTrait
{
    private ?EntityManagerInterface $derivedUploadSessionEntityManager = null;

    private function derivedUploadSessionEntityManager(): EntityManagerInterface
    {
        if ($this->derivedUploadSessionEntityManager instanceof EntityManagerInterface) {
            return $this->derivedUploadSessionEntityManager;
        }

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__.'/../../src/Derived'],
            true,
        );
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $config);

        $entityManager = new EntityManager($connection, $config);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getClassMetadata(DerivedUploadSession::class);
        $schemaTool->createSchema([$metadata]);

        return $this->derivedUploadSessionEntityManager = $entityManager;
    }
}
