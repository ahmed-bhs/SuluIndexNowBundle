<?php

namespace Linderp\SuluIndexNowBundle\Service;

use Psr\Log\LoggerInterface;

class SiteMapTranslator
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @return array<int, string>
     */
    public function translateUrls(string $sitemapUrl): array
    {
        $visitedSitemaps = [];

        return array_values(array_unique($this->translateSitemap($sitemapUrl, $visitedSitemaps)));
    }

    /**
     * @param array<string, true> $visitedSitemaps
     *
     * @return array<int, string>
     */
    private function translateSitemap(string $sitemapUrl, array &$visitedSitemaps): array
    {
        if (isset($visitedSitemaps[$sitemapUrl])) {
            return [];
        }

        $visitedSitemaps[$sitemapUrl] = true;

        // Load remote XML (with error handling)
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'SuluIndexNowBot/1.0',
            ],
        ]);

        $xmlContent = @file_get_contents($sitemapUrl, false, $context);
        if ($xmlContent === false) {
            $this->logger->warning('IndexNow sitemap fetch failed', [
                'sitemapUrl' => $sitemapUrl,
            ]);
            return [];
        }

        $sitemap = simplexml_load_string($xmlContent);
        if (!$sitemap) {
            $this->logger->warning('IndexNow sitemap XML parse failed', [
                'sitemapUrl' => $sitemapUrl,
            ]);
            return [];
        }

        $namespace = $sitemap->getDocNamespaces(true)[''] ?? null;
        $entries = null !== $namespace
            ? $sitemap->children($namespace)
            : $sitemap;

        if ($sitemap->getName() === 'sitemapindex') {
            $urls = [];

            foreach ($entries->sitemap as $entry) {
                $childSitemapUrl = trim((string) $entry->loc);
                if ($childSitemapUrl === '') {
                    continue;
                }

                $urls = array_merge(
                    $urls,
                    $this->translateSitemap($childSitemapUrl, $visitedSitemaps),
                );
            }

            return $urls;
        }

        $urls = [];
        foreach ($entries->url as $entry) {
            $loc = trim((string) $entry->loc);
            if ($loc === '') {
                continue;
            }
            $urls[] = $loc;
        }
        return $urls;
    }
}
