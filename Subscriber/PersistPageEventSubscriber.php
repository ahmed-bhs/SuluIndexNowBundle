<?php

namespace Linderp\SuluIndexNowBundle\Subscriber;

use Linderp\SuluIndexNowBundle\Service\HostExtractor;
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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

readonly class PersistPageEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%sulu_index_now.key%')]
        private string $indexNowKey,
        private IndexNowSubmitter $submitter,
        private HostExtractor $hostExtractor,
        private RequestStack $requestStack,
        private RouteGeneratorInterface $routeGenerator,
        private LoggerInterface $logger
    ) {}
    public static function getSubscribedEvents(): array
    {
        return [PageWorkflowTransitionAppliedEvent::class => 'onPublish'];
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
            $this->submitter->submit($this->hostExtractor->normalizeHost($request), $this->indexNowKey, [$url]);
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
        } catch (\Throwable $exception) {
            $this->logger->warning('IndexNow URL generation failed', [
                'locale' => $locale,
                'webspace' => $webspaceKey,
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
