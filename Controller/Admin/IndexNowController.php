<?php

namespace Linderp\SuluIndexNowBundle\Controller\Admin;

use Linderp\SuluIndexNowBundle\Service\IndexNowSubmitter;
use Linderp\SuluIndexNowBundle\Service\SiteMapTranslator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IndexNowController extends AbstractController
{
    private const SUBMIT_BATCH_SIZE = 1000;
    public function __construct(
        #[Autowire('%sulu_index_now.key%')]
        private readonly string $indexNowKey,
        private readonly IndexNowSubmitter $submitter,
        private readonly SiteMapTranslator $translator,
    ) {}
    #[Route(path: '/admin/api/index-now/start', name: 'app.index-now.start', methods: ['POST'])]
    public function indexNow(Request $request): Response
    {
        $urls = $this->translator->translateUrls($this->getSiteMapUrl($request));
        $batches = array_chunk($urls, self::SUBMIT_BATCH_SIZE);
        $responses = [];
        $submitted = 0;
        foreach ($batches as $index => $batch) {
            $responses[$index] = $this->submitter->submit(
                $request->getHost(),
                $this->indexNowKey,
                $batch
            );
            $submitted += count($batch);
        }

        $summary = $this->createSubmissionSummary($responses);

        return new JsonResponse([
            "responses" => $responses,
            "urls" => $urls,
            "submitted" => $submitted,
            "batchSize" => self::SUBMIT_BATCH_SIZE,
            "batchCount" => count($batches),
            "submittedAt" => (new \DateTimeImmutable())->format(\DATE_ATOM),
            "summary" => $summary,
        ]);
    }
    #[Route(path: '/admin/api/index-now/urls', name: 'app.index-now.urls', methods: ['GET'])]
    public function getUrls(Request $request): Response
    {
        $urls = $this->translator->translateUrls($this->getSiteMapUrl($request));
        return new JsonResponse(["urls" => $urls]);
    }
    private function getSiteMapUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost() . '/sitemap.xml';
    }

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
    private function createSubmissionSummary(array $responses): array
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
}
