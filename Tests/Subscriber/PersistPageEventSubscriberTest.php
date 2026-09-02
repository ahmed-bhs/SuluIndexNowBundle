<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\Tests\Subscriber;

use Linderp\SuluIndexNowBundle\Event\IndexNowUrlEvent;
use Linderp\SuluIndexNowBundle\Service\IndexNowSubmitter;
use Linderp\SuluIndexNowBundle\Subscriber\PersistPageEventSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sulu\Route\Application\Routing\Generator\RouteGeneratorInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PersistPageEventSubscriberTest extends TestCase
{
    public function testItQueuesAUrlDispatchedThroughTheHook(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return new MockResponse('accepted', ['http_code' => 202]);
        });
        $submitter = new IndexNowSubmitter(
            ['IndexNow' => 'https://api.indexnow.org/indexnow'],
            $httpClient,
            new NullLogger(),
        );
        $subscriber = new PersistPageEventSubscriber(
            'secret',
            $submitter,
            new RequestStack(),
            $this->createMock(RouteGeneratorInterface::class),
            new NullLogger(),
        );

        $subscriber->onIndexNowUrl(new IndexNowUrlEvent('https://refashion.ch/de/events/1/example'));
        $subscriber->onTerminate(new TerminateEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/admin/api/events'),
            new Response(),
        ));

        self::assertCount(1, $requests);
        self::assertSame([
            'host' => 'refashion.ch',
            'key' => 'secret',
            'urlList' => ['https://refashion.ch/de/events/1/example'],
        ], json_decode($requests[0][2]['body'], true, 512, JSON_THROW_ON_ERROR));
    }
}
