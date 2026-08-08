<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\Tests\Service;

use Linderp\SuluIndexNowBundle\Service\IndexNowSubmitter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IndexNowSubmitterTest extends TestCase
{
    public function testItDoesNotCallSearchEnginesWithoutUrls(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('The HTTP client must not be called for an empty URL list.');
        });
        $submitter = new IndexNowSubmitter(
            ['IndexNow' => 'https://api.indexnow.org/indexnow'],
            $client,
            new NullLogger(),
        );

        self::assertSame([], $submitter->submit('example.com', 'secret', []));
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testItSubmitsTheSamePayloadToEveryConfiguredEndpoint(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return new MockResponse('accepted', ['http_code' => 202]);
        });
        $submitter = new IndexNowSubmitter([
            'IndexNow' => 'https://api.indexnow.org/indexnow',
            'Bing' => 'https://www.bing.com/indexnow',
        ], $client, new NullLogger());

        $result = $submitter->submit('example.com', 'secret', [
            'https://example.com/de/one',
            'https://example.com/de/two',
        ]);

        self::assertSame([
            'IndexNow' => ['status' => 202, 'body' => 'accepted'],
            'Bing' => ['status' => 202, 'body' => 'accepted'],
        ], $result);
        self::assertCount(2, $requests);
        self::assertSame(['POST', 'https://api.indexnow.org/indexnow'], array_slice($requests[0], 0, 2));
        self::assertSame(['POST', 'https://www.bing.com/indexnow'], array_slice($requests[1], 0, 2));

        foreach ($requests as [, , $options]) {
            self::assertSame([
                'host' => 'example.com',
                'key' => 'secret',
                'urlList' => [
                    'https://example.com/de/one',
                    'https://example.com/de/two',
                ],
            ], json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR));
            self::assertSame(5.0, $options['timeout']);
            self::assertSame(10.0, $options['max_duration']);
        }
    }
}
