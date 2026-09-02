<?php

namespace Linderp\SuluIndexNowBundle\Subscriber;

use Linderp\SuluIndexNowBundle\Event\IndexNowUrlEvent;
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
    /** @var array<string, array{host: string, key: string, urls: array<string>}> */
    private array $pendingSubmissions = [];

    public function __construct(
        #[Autowire('%sulu_index_now.key%')]
        private string $indexNowKey,
        private IndexNowSubmitter $submitter,
        private RequestStack $requestStack,
        private RouteGeneratorInterface $routeGenerator,
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
        $this->queueUrl($event->getUrl(), $event->getHost());
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
            $this->queueUrl($url, $request->getHost());
        }
    }

    private function queueUrl(string $url, ?string $host = null): void
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
        ];
        $this->pendingSubmissions[$submissionKey]['urls'][] = $url;
    }

    public function onTerminate(TerminateEvent $event): void
    {
        foreach ($this->pendingSubmissions as $submission) {
            $this->submitter->submit(
                $submission['host'],
                $submission['key'],
                array_values(array_unique($submission['urls'])),
            );
        }

        $this->pendingSubmissions = [];
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
