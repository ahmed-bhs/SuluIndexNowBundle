<?php

namespace Linderp\SuluIndexNowBundle\Subscriber;

use Linderp\SuluIndexNowBundle\Entity\IndexNowSubmission;
use Linderp\SuluIndexNowBundle\Event\IndexNowUrlEvent;
use Linderp\SuluIndexNowBundle\Service\IndexNowRunRecorder;
use Linderp\SuluIndexNowBundle\Service\IndexNowSubmitter;
use Psr\Log\LoggerInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Page\Domain\Event\PageWorkflowTransitionAppliedEvent;
use Sulu\Page\Domain\Model\PageDimensionContentInterface;
use Sulu\Route\Application\Routing\Generator\RouteGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PersistPageEventSubscriber implements EventSubscriberInterface
{
    private const SUBMIT_BATCH_SIZE = 1000;

    /** @var array<string, array{host: string, key: string, urls: array<string>, sources: array<string, true>}> */
    private array $pendingSubmissions = [];

    public function __construct(
        #[Autowire('%sulu_index_now.key%')]
        private string $indexNowKey,
        private IndexNowSubmitter $submitter,
        private RequestStack $requestStack,
        private RouteGeneratorInterface $routeGenerator,
        private IndexNowRunRecorder $recorder,
        private LoggerInterface $logger
    ) {}
    public static function getSubscribedEvents(): array
    {
        return [
            PageWorkflowTransitionAppliedEvent::class => 'onPublish',
            IndexNowUrlEvent::class => 'onIndexNowUrl',
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onIndexNowUrl(IndexNowUrlEvent $event): void
    {
        $this->queueUrl($event->getUrl(), $event->getHost(), 'event');
    }
    public function onPublish(PageWorkflowTransitionAppliedEvent $event): void
    {
        if ($event->getWorkflowTransitionName() !== WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        $page = $event->getPage();
        $locale = $event->getResourceLocale();
        if (null === $locale) {
            return;
        }

        $dimensionContent = $page->getDimensionContents()->filter(
            static fn($content): bool => $content->getLocale() === $locale,
        )->first();
        if (!$dimensionContent instanceof PageDimensionContentInterface) {
            $this->logger->warning('IndexNow URL resolution failed', [
                'locale' => $locale,
                'webspace' => $page->getWebspaceKey(),
            ]);
            return;
        }

        if ($dimensionContent->getSeoNoIndex()) {
            return;
        }

        $resourceSegment = $dimensionContent->getRoute()?->getSlug();
        if (!is_string($resourceSegment) || $resourceSegment === '') {
            $this->logger->warning('IndexNow URL resolution failed', [
                'locale' => $locale,
                'webspace' => $page->getWebspaceKey(),
            ]);
            return;
        }

        $url = $this->buildUrl($request, $locale, $resourceSegment, $page->getWebspaceKey());
        if ($url) {
            $this->queueUrl($url, $request->getHost(), 'page_publish');
        }
    }

    private function queueUrl(string $url, ?string $host = null, string $source = 'event'): void
    {
        $host ??= parse_url($url, PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return;
        }

        $submissionKey = $host . '|' . $this->indexNowKey;
        $this->pendingSubmissions[$submissionKey] ??= [
            'host' => $host,
            'key' => $this->indexNowKey,
            'urls' => [],
            'sources' => [],
        ];
        $this->pendingSubmissions[$submissionKey]['urls'][] = $url;
        $this->pendingSubmissions[$submissionKey]['sources'][$source] = true;
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $pendingSubmissions = $this->pendingSubmissions;
        $this->pendingSubmissions = [];

        foreach ($pendingSubmissions as $submission) {
            $urls = array_values(array_unique($submission['urls']));
            $responses = [];
            $startedAt = microtime(true);

            foreach (array_chunk($urls, self::SUBMIT_BATCH_SIZE) as $index => $batch) {
                $responses[$index] = $this->submitter->submit(
                    $submission['host'],
                    $submission['key'],
                    $batch,
                );
            }

            $this->recorder->record(
                $this->recorder->createSummary($responses),
                IndexNowSubmission::TRIGGER_AUTOMATIC,
                implode(',', array_keys($submission['sources'])),
                $submission['host'],
                count($urls),
                (int) round((microtime(true) - $startedAt) * 1000),
            );
        }
    }
    public function buildUrl(
        Request $request,
        string $locale,
        string $resourceSegment,
        string $webspaceKey
    ): ?string {
        if (!$resourceSegment) {
            return null;
        }

        try {
            return $this->routeGenerator->generate(
                $resourceSegment,
                $locale,
                $webspaceKey,
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('IndexNow URL generation failed', [
                'locale' => $locale,
                'webspace' => $webspaceKey,
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
