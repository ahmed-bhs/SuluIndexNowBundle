<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\Tests\Controller;

use Linderp\SuluIndexNowBundle\Controller\Admin\IndexNowController;
use Linderp\SuluIndexNowBundle\Service\IndexNowSubmitter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sulu\Bundle\WebsiteBundle\Sitemap\Sitemap;
use Sulu\Bundle\WebsiteBundle\Sitemap\SitemapProviderInterface;
use Sulu\Bundle\WebsiteBundle\Sitemap\SitemapProviderPoolInterface;
use Sulu\Bundle\WebsiteBundle\Sitemap\SitemapUrl;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;

final class IndexNowControllerTest extends TestCase
{
    public function testItBuildsUrlsFromSuluSitemapProviders(): void
    {
        $provider = $this->createMock(SitemapProviderInterface::class);
        $provider->method('build')->willReturnCallback(
            static function (int $page, string $scheme, string $host): array {
                if ($page === 2) {
                    return [
                        new SitemapUrl('', 'de', 'de'),
                        new SitemapUrl("$scheme://$host/de/two", 'de', 'de'),
                    ];
                }

                return [new SitemapUrl("$scheme://$host/de/one", 'de', 'de')];
            },
        );

        $pool = $this->createMock(SitemapProviderPoolInterface::class);
        $pool->expects(self::once())
            ->method('getIndex')
            ->with('https', 'refashion.ch')
            ->willReturn([new Sitemap('pages', 2)]);
        $pool->expects(self::once())
            ->method('getProvider')
            ->with('pages')
            ->willReturn($provider);

        $controller = new IndexNowController(
            'secret',
            new IndexNowSubmitter([], new MockHttpClient(), new NullLogger()),
            $pool,
        );

        $response = $controller->getUrls(Request::create('https://refashion.ch/admin/api/index-now/urls'));

        self::assertSame([
            'urls' => [
                'https://refashion.ch/de/one',
                'https://refashion.ch/de/two',
            ],
        ], json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }
}
