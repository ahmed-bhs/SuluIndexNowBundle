<?php

namespace Linderp\SuluIndexNowBundle\Controller\Admin;

use Linderp\SuluIndexNowBundle\Entity\IndexNowSubmission;
use Linderp\SuluIndexNowBundle\Repository\IndexNowSubmissionRepository;
use Linderp\SuluIndexNowBundle\Service\IndexNowRunRecorder;
use Linderp\SuluIndexNowBundle\Service\IndexNowSubmitter;
use Sulu\Bundle\WebsiteBundle\Sitemap\SitemapProviderPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IndexNowController extends AbstractController
{
    private const SUBMIT_BATCH_SIZE = 1000;
    private const HISTORY_LIMIT = 20;
    private const STATISTICS_LIMIT = 20;

    public function __construct(
        #[Autowire('%sulu_index_now.key%')]
        private readonly string $indexNowKey,
        private readonly IndexNowSubmitter $submitter,
        private readonly SitemapProviderPoolInterface $sitemapProviderPool,
        private readonly IndexNowRunRecorder $recorder,
        private readonly IndexNowSubmissionRepository $submissionRepository,
    ) {}

    #[Route(path: '/admin/api/index-now/start', name: 'app.index-now.start', methods: ['POST'])]
    public function indexNow(Request $request): Response
    {
        $host = $request->getHost();
        $urls = $this->getSiteMapUrls($request);
        $batches = array_chunk($urls, self::SUBMIT_BATCH_SIZE);
        $responses = [];
        $submitted = 0;
        $startedAt = microtime(true);
        foreach ($batches as $index => $batch) {
            $responses[$index] = $this->submitter->submit(
                $host,
                $this->indexNowKey,
                $batch
            );
            $submitted += count($batch);
        }
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $summary = $this->recorder->createSummary($responses);
        $submission = $this->recorder->record(
            $summary,
            IndexNowSubmission::TRIGGER_MANUAL,
            'admin',
            $host,
            $submitted,
            $durationMs,
        );

        return new JsonResponse([
            "responses" => $responses,
            "urls" => $urls,
            "submitted" => $submitted,
            "batchSize" => self::SUBMIT_BATCH_SIZE,
            "batchCount" => count($batches),
            "submittedAt" => ($submission?->getSubmittedAt() ?? new \DateTimeImmutable())->format(\DATE_ATOM),
            "summary" => $summary,
            "durationMs" => $durationMs,
            "lastRun" => $this->findLastRun($host),
            "lastSuccess" => $this->findLastSuccess($host),
            "statistics" => $this->submissionRepository->findStatistics($host, self::STATISTICS_LIMIT),
        ] + $this->buildHistoryPayload($host, 1, self::HISTORY_LIMIT, []));
    }

    #[Route(path: '/admin/api/index-now/urls', name: 'app.index-now.urls', methods: ['GET'])]
    public function getUrls(Request $request): Response
    {
        $urls = $this->getSiteMapUrls($request);
        return new JsonResponse(["urls" => $urls]);
    }

    #[Route(path: '/admin/api/index-now/status', name: 'app.index-now.status', methods: ['GET'])]
    public function getStatus(Request $request): Response
    {
        $host = $request->getHost();

        return new JsonResponse([
            "lastRun" => $this->findLastRun($host),
            "lastSuccess" => $this->findLastSuccess($host),
            "statistics" => $this->submissionRepository->findStatistics($host, self::STATISTICS_LIMIT),
        ] + $this->buildHistoryPayload(
            $host,
            $request->query->getInt('page', 1),
            $request->query->getInt('limit', self::HISTORY_LIMIT),
            [
                'status' => $request->query->getString('status'),
                'trigger' => $request->query->getString('trigger'),
            ],
        ));
    }

    /**
     * @param array{status?: string|null, trigger?: string|null} $filters
     *
     * @return array{history: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
     */
    private function buildHistoryPayload(string $host, int $page, int $limit, array $filters): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));

        $result = $this->submissionRepository->findPage($host, $page, $limit, $filters);

        return [
            'history' => array_map(
                static fn(IndexNowSubmission $submission): array => $submission->toArray(),
                $result['items'],
            ),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $result['total'],
                'pages' => (int) ceil($result['total'] / $limit),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLastRun(string $host): ?array
    {
        return $this->submissionRepository->findLast($host)?->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLastSuccess(string $host): ?array
    {
        return $this->submissionRepository->findLastSuccessful($host)?->toArray();
    }

    /**
     * @return array<int, string>
     */
    private function getSiteMapUrls(Request $request): array
    {
        $scheme = $request->getScheme();
        $host = $request->getHost();
        $urls = [];

        foreach ($this->sitemapProviderPool->getIndex($scheme, $host) as $sitemap) {
            $provider = $this->sitemapProviderPool->getProvider($sitemap->getAlias());

            for ($page = 1; $page <= $sitemap->getMaxPage(); ++$page) {
                foreach ($provider->build($page, $scheme, $host) as $sitemapUrl) {
                    $loc = trim($sitemapUrl->getLoc());
                    if ($loc !== '') {
                        $urls[] = $loc;
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }
}
