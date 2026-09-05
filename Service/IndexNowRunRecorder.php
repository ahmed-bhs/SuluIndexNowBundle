<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\Service;

use Linderp\SuluIndexNowBundle\Entity\IndexNowSubmission;
use Linderp\SuluIndexNowBundle\Repository\IndexNowSubmissionRepository;
use Psr\Log\LoggerInterface;

class IndexNowRunRecorder
{
    public function __construct(
        private readonly IndexNowSubmissionRepository $repository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<int, array<string, array{status: int|string, body: string}>> $responses
     *
     * @return array{successfulEngines: int, failedEngines: int, engines: array<int, array{
     *     name: string,
     *     status: string,
     *     successfulBatches: int,
     *     failedBatches: int,
     *     totalBatches: int,
     *     errors: array<int, string>
     * }>}
     */
    public function createSummary(array $responses): array
    {
        $engines = [];

        foreach ($responses as $batchResponses) {
            foreach ($batchResponses as $name => $response) {
                if (!isset($engines[$name])) {
                    $engines[$name] = [
                        'name' => $name,
                        'status' => 'success',
                        'successfulBatches' => 0,
                        'failedBatches' => 0,
                        'totalBatches' => 0,
                        'errors' => [],
                    ];
                }

                $engines[$name]['totalBatches']++;
                $status = $response['status'];
                $isSuccessful = is_numeric($status) && (int) $status >= 200 && (int) $status < 300;

                if ($isSuccessful) {
                    $engines[$name]['successfulBatches']++;
                    continue;
                }

                $engines[$name]['status'] = 'error';
                $engines[$name]['failedBatches']++;

                $error = trim($response['body']);
                if ($error !== '' && !in_array($error, $engines[$name]['errors'], true)) {
                    $engines[$name]['errors'][] = $error;
                }
            }
        }

        $engines = array_values($engines);
        $successfulEngines = count(array_filter(
            $engines,
            static fn(array $engine): bool => $engine['status'] === 'success',
        ));

        return [
            'successfulEngines' => $successfulEngines,
            'failedEngines' => count($engines) - $successfulEngines,
            'engines' => $engines,
        ];
    }

    /**
     * @param array{successfulEngines: int, failedEngines: int, engines: array<int, array<string, mixed>>} $summary
     */
    public function record(
        array $summary,
        string $trigger,
        string $source,
        string $host,
        int $urlCount,
    ): ?IndexNowSubmission {
        if ([] === $summary['engines']) {
            return null;
        }

        $submission = IndexNowSubmission::create(
            $trigger,
            $source,
            $host,
            $urlCount,
            $summary['successfulEngines'],
            $summary['failedEngines'],
            $summary['engines'],
        );

        try {
            $this->repository->save($submission);
        } catch (\Throwable $exception) {
            $this->logger->error('IndexNow submission could not be recorded', [
                'exception' => $exception,
                'host' => $host,
            ]);

            return null;
        }

        return $submission;
    }
}
