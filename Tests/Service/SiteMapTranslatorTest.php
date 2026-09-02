<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\Tests\Service;

use Linderp\SuluIndexNowBundle\Service\SiteMapTranslator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class SiteMapTranslatorTest extends TestCase
{
    public function testItExtractsNonEmptyLocationsFromAStandardSitemap(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/de/one</loc></url>
                <url><loc></loc></url>
                <url><loc>https://example.com/de/two</loc></url>
            </urlset>
            XML;
        $url = 'data://text/plain;base64,' . base64_encode($xml);

        self::assertSame([
            'https://example.com/de/one',
            'https://example.com/de/two',
        ], (new SiteMapTranslator(new NullLogger()))->translateUrls($url));
    }

    public function testItFollowsSitemapIndexesAndDeduplicatesLocations(): void
    {
        $childXml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/de/one</loc></url>
                <url><loc>https://example.com/de/two</loc></url>
            </urlset>
            XML;
        $childUrl = 'data://text/plain;base64,' . base64_encode($childXml);
        $indexXml = sprintf(
            <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                    <sitemap><loc>%s</loc></sitemap>
                    <sitemap><loc>%s</loc></sitemap>
                </sitemapindex>
                XML,
            $childUrl,
            $childUrl,
        );
        $indexUrl = 'data://text/plain;base64,' . base64_encode($indexXml);

        self::assertSame([
            'https://example.com/de/one',
            'https://example.com/de/two',
        ], (new SiteMapTranslator(new NullLogger()))->translateUrls($indexUrl));
    }

    public function testItLogsAndReturnsNoUrlsForInvalidXml(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('IndexNow sitemap XML parse failed', self::arrayHasKey('sitemapUrl'));

        $url = 'data://text/plain;base64,' . base64_encode('<urlset>');

        $previous = libxml_use_internal_errors(true);
        try {
            self::assertSame([], (new SiteMapTranslator($logger))->translateUrls($url));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
