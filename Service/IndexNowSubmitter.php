<?php

namespace Linderp\SuluIndexNowBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

readonly class IndexNowSubmitter
{
    private const REQUEST_TIMEOUT_SECONDS = 5;
    private const REQUEST_MAX_DURATION_SECONDS = 10;

    /**
     * @param array<string, string> $endpoints
     */
    public function __construct(
        #[Autowire('%sulu_index_now.search_engines%')]
        /** @var array<string, string> */
        private array           $endpoints,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<int, string> $urls
     *
     * @return array<string, array{status: int|string, body: string}>
     */
    public function submit(string $host, string $key, array $urls): array
    {
        if (empty($urls)) {
            return [];
        }
        $payload = [
            'host'        => $host,
            'key'         => $key,
            'urlList'     => $urls,
        ];

        /** @var array<string, array{endpoint: string, response: ResponseInterface}> $pendingResponses */
        $pendingResponses = [];
        $responses = [];
        foreach ($this->endpoints as $name => $endpoint) {
            try {
                // Creating all responses first lets CurlHttpClient perform the
                // remote submissions concurrently instead of serially.
                $pendingResponses[$name] = [
                    'endpoint' => $endpoint,
                    'response' => $this->httpClient->request('POST', $endpoint, [
                        'json' => $payload,
                        'headers' => [
                            'Content-Type' => 'application/json; charset=utf-8',
                        ],
                        'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                        'max_duration' => self::REQUEST_MAX_DURATION_SECONDS,
                    ]),
                ];
            } catch (TransportExceptionInterface $e) {
                $responses[$name] = $this->createErrorResponse($e, $payload, $endpoint, $name);
            }
        }

        foreach ($pendingResponses as $name => ['endpoint' => $endpoint, 'response' => $response]) {
            try {
                $responses[$name] = [
                    'status' => $response->getStatusCode(),
                    'body'   => $response->getContent(false),
                ];
                $this->logger->debug('Index now submitted to: ' . $endpoint . ', status: ' . $response->getStatusCode());
            } catch (TransportExceptionInterface|RedirectionExceptionInterface|ClientExceptionInterface|ServerExceptionInterface $e) {
                $responses[$name] = $this->createErrorResponse($e, $payload, $endpoint, $name);
            }
        }

        return $responses;
    }

    /**
     * @param array{host: string, key: string, urlList: array<int, string>} $payload
     *
     * @return array{status: string, body: string}
     */
    private function createErrorResponse(
        \Throwable $exception,
        array $payload,
        string $endpoint,
        string $name,
    ): array {
        $this->logger->error($exception->getMessage(), [
            'payload' => $payload,
            'endpoint' => $endpoint,
            'name' => $name,
        ]);

        return [
            'status' => 'error',
            'body' => $exception->getMessage(),
        ];
    }
}
