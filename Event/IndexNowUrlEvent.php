<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\Event;

final readonly class IndexNowUrlEvent
{
    public function __construct(
        private string $url,
        private ?string $host = null,
    ) {}

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }
}
