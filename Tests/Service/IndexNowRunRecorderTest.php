<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\Tests\Service;

use Linderp\SuluIndexNowBundle\Entity\IndexNowSubmission;
use Linderp\SuluIndexNowBundle\Repository\IndexNowSubmissionRepository;
use Linderp\SuluIndexNowBundle\Service\IndexNowRunRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class IndexNowRunRecorderTest extends TestCase
{
    public function testItReportsEveryEngineAsSuccessfulWhenAllBatchesAreAccepted(): void
    {
        $recorder = $this->createRecorder();

        $summary = $recorder->createSummary([
            ['Bing' => ['status' => 200, 'body' => ''], 'Yandex' => ['status' => 202, 'body' => '']],
            ['Bing' => ['status' => 200, 'body' => ''], 'Yandex' => ['status' => 200, 'body' => '']],
        ]);

        self::assertSame(2, $summary['successfulEngines']);
        self::assertSame(0, $summary['failedEngines']);
    }

    public function testItMarksAnEngineAsFailedWhenOneOfItsBatchesIsRejected(): void
    {
        $recorder = $this->createRecorder();

        $summary = $recorder->createSummary([
            ['Bing' => ['status' => 200, 'body' => '']],
            ['Bing' => ['status' => 429, 'body' => 'too many requests']],
        ]);

        self::assertSame(0, $summary['successfulEngines']);
        self::assertSame(1, $summary['failedEngines']);
        self::assertSame(['too many requests'], $summary['engines'][0]['errors']);
        self::assertSame(1, $summary['engines'][0]['successfulBatches']);
        self::assertSame(1, $summary['engines'][0]['failedBatches']);
    }

    public function testItDoesNotRepeatIdenticalErrorMessages(): void
    {
        $recorder = $this->createRecorder();

        $summary = $recorder->createSummary([
            ['Bing' => ['status' => 'error', 'body' => 'timeout']],
            ['Bing' => ['status' => 'error', 'body' => 'timeout']],
        ]);

        self::assertSame(['timeout'], $summary['engines'][0]['errors']);
    }

    public function testItStoresASubmissionForARunThatReachedAtLeastOneEngine(): void
    {
        $repository = $this->createMock(IndexNowSubmissionRepository::class);
        $repository->expects(self::once())->method('save');

        $recorder = new IndexNowRunRecorder($repository, new NullLogger());
        $summary = $recorder->createSummary([['Bing' => ['status' => 200, 'body' => '']]]);

        $submission = $recorder->record(
            $summary,
            IndexNowSubmission::TRIGGER_MANUAL,
            'admin',
            'example.com',
            12,
        );

        self::assertNotNull($submission);
        self::assertSame(IndexNowSubmission::TRIGGER_MANUAL, $submission->getTrigger());
        self::assertSame('example.com', $submission->getHost());
        self::assertSame(12, $submission->getUrlCount());
        self::assertTrue($submission->isSuccessful());
    }

    public function testItStoresNothingWhenNoEngineWasContacted(): void
    {
        $repository = $this->createMock(IndexNowSubmissionRepository::class);
        $repository->expects(self::never())->method('save');

        $recorder = new IndexNowRunRecorder($repository, new NullLogger());

        self::assertNull($recorder->record(
            $recorder->createSummary([]),
            IndexNowSubmission::TRIGGER_AUTOMATIC,
            'page_publish',
            'example.com',
            0,
        ));
    }

    public function testAFailingStorageDoesNotBreakTheRun(): void
    {
        $repository = $this->createMock(IndexNowSubmissionRepository::class);
        $repository->method('save')->willThrowException(new \RuntimeException('database is gone'));

        $recorder = new IndexNowRunRecorder($repository, new NullLogger());
        $summary = $recorder->createSummary([['Bing' => ['status' => 200, 'body' => '']]]);

        self::assertNull($recorder->record(
            $summary,
            IndexNowSubmission::TRIGGER_MANUAL,
            'admin',
            'example.com',
            1,
        ));
    }

    private function createRecorder(): IndexNowRunRecorder
    {
        return new IndexNowRunRecorder(
            $this->createMock(IndexNowSubmissionRepository::class),
            new NullLogger(),
        );
    }
}
