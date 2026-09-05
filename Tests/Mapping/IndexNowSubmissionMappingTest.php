<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\Tests\Mapping;

use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\Persistence\Mapping\Driver\SymfonyFileLocator;
use Linderp\SuluIndexNowBundle\Entity\IndexNowSubmission;
use Linderp\SuluIndexNowBundle\Repository\IndexNowSubmissionRepository;
use PHPUnit\Framework\TestCase;

final class IndexNowSubmissionMappingTest extends TestCase
{
    public function testTheSubmissionIsMappedToItsOwnTable(): void
    {
        $metadata = $this->loadMetadata();

        self::assertSame('inw_submission', $metadata->getTableName());
        self::assertSame(IndexNowSubmissionRepository::class, $metadata->customRepositoryClassName);
    }

    public function testEveryPropertyOfTheSubmissionIsPersisted(): void
    {
        $metadata = $this->loadMetadata();

        self::assertEqualsCanonicalizing([
            'id',
            'submittedAt',
            'trigger',
            'source',
            'host',
            'urlCount',
            'status',
            'successfulEngines',
            'failedEngines',
            'engines',
        ], $metadata->getFieldNames());
    }

    public function testTheTriggerColumnIsQuotedBecauseItIsAReservedSqlWord(): void
    {
        $metadata = $this->loadMetadata();

        self::assertTrue((bool) $metadata->getFieldMapping('trigger')['quoted']);
    }

    public function testThePerEngineResultIsStoredAsJson(): void
    {
        $metadata = $this->loadMetadata();

        self::assertSame('json', $metadata->getFieldMapping('engines')['type']);
        self::assertSame('datetime_immutable', $metadata->getFieldMapping('submittedAt')['type']);
    }

    private function loadMetadata(): \Doctrine\ORM\Mapping\ClassMetadata
    {
        $driver = new XmlDriver(
            new SymfonyFileLocator(
                [__DIR__ . '/../../Resources/config/doctrine' => 'Linderp\SuluIndexNowBundle\Entity'],
                '.orm.xml',
            ),
            '.orm.xml',
            false,
        );

        $metadata = new \Doctrine\ORM\Mapping\ClassMetadata(IndexNowSubmission::class);
        $metadata->initializeReflection(new \Doctrine\Persistence\Mapping\RuntimeReflectionService());
        $driver->loadMetadataForClass(IndexNowSubmission::class, $metadata);

        return $metadata;
    }
}
