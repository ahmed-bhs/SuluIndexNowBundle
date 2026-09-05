<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\Tests\Entity;

use Linderp\SuluIndexNowBundle\Entity\IndexNowSubmission;
use PHPUnit\Framework\TestCase;

final class IndexNowSubmissionTest extends TestCase
{
    public function testARunWithoutAnyFailureIsSuccessful(): void
    {
        $submission = $this->createSubmission(3, 0);

        self::assertSame(IndexNowSubmission::STATUS_SUCCESS, $submission->getStatus());
        self::assertTrue($submission->isSuccessful());
    }

    public function testARunWithSomeFailuresIsPartial(): void
    {
        $submission = $this->createSubmission(2, 1);

        self::assertSame(IndexNowSubmission::STATUS_PARTIAL, $submission->getStatus());
        self::assertFalse($submission->isSuccessful());
    }

    public function testARunWhereEveryEngineFailedIsAnError(): void
    {
        $submission = $this->createSubmission(0, 3);

        self::assertSame(IndexNowSubmission::STATUS_ERROR, $submission->getStatus());
        self::assertFalse($submission->isSuccessful());
    }

    public function testItExposesTheRunForTheAdminInterface(): void
    {
        $submittedAt = new \DateTimeImmutable('2026-09-05 12:08:00');
        $submission = IndexNowSubmission::create(
            IndexNowSubmission::TRIGGER_AUTOMATIC,
            'page_publish',
            'example.com',
            5,
            1,
            0,
            [['name' => 'Bing', 'status' => 'success']],
            $submittedAt,
        );

        $data = $submission->toArray();

        self::assertSame($submittedAt->format(\DATE_ATOM), $data['submittedAt']);
        self::assertSame(IndexNowSubmission::TRIGGER_AUTOMATIC, $data['trigger']);
        self::assertSame('page_publish', $data['source']);
        self::assertSame(5, $data['urlCount']);
        self::assertSame(IndexNowSubmission::STATUS_SUCCESS, $data['status']);
    }

    public function testARunRecordedWithoutADurationExposesNone(): void
    {
        self::assertNull($this->createSubmission(1, 0)->getDurationMs());
        self::assertArrayHasKey('durationMs', $this->createSubmission(1, 0)->toArray());
    }

    private function createSubmission(int $successfulEngines, int $failedEngines): IndexNowSubmission
    {
        return IndexNowSubmission::create(
            IndexNowSubmission::TRIGGER_MANUAL,
            'admin',
            'example.com',
            10,
            $successfulEngines,
            $failedEngines,
            [],
        );
    }
}
