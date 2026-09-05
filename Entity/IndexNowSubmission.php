<?php

declare(strict_types=1);

namespace Linderp\SuluIndexNowBundle\Entity;

class IndexNowSubmission
{
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_AUTOMATIC = 'automatic';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_ERROR = 'error';

    private ?int $id = null;

    private \DateTimeImmutable $submittedAt;

    private string $trigger;

    private string $source;

    private string $host;

    private int $urlCount;

    private string $status;

    private int $successfulEngines;

    private int $failedEngines;

    /** @var array<int, array<string, mixed>> */
    private array $engines = [];

    /**
     * @param array<int, array<string, mixed>> $engines
     */
    private function __construct(
        \DateTimeImmutable $submittedAt,
        string $trigger,
        string $source,
        string $host,
        int $urlCount,
        int $successfulEngines,
        int $failedEngines,
        array $engines,
    ) {
        $this->submittedAt = $submittedAt;
        $this->trigger = $trigger;
        $this->source = $source;
        $this->host = $host;
        $this->urlCount = $urlCount;
        $this->successfulEngines = $successfulEngines;
        $this->failedEngines = $failedEngines;
        $this->engines = $engines;
        $this->status = self::resolveStatus($successfulEngines, $failedEngines);
    }

    /**
     * @param array<int, array<string, mixed>> $engines
     */
    public static function create(
        string $trigger,
        string $source,
        string $host,
        int $urlCount,
        int $successfulEngines,
        int $failedEngines,
        array $engines,
        ?\DateTimeImmutable $submittedAt = null,
    ): self {
        return new self(
            $submittedAt ?? new \DateTimeImmutable(),
            $trigger,
            $source,
            $host,
            $urlCount,
            $successfulEngines,
            $failedEngines,
            $engines,
        );
    }

    private static function resolveStatus(int $successfulEngines, int $failedEngines): string
    {
        if (0 === $failedEngines) {
            return self::STATUS_SUCCESS;
        }

        return 0 === $successfulEngines ? self::STATUS_ERROR : self::STATUS_PARTIAL;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getUrlCount(): int
    {
        return $this->urlCount;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getSuccessfulEngines(): int
    {
        return $this->successfulEngines;
    }

    public function getFailedEngines(): int
    {
        return $this->failedEngines;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEngines(): array
    {
        return $this->engines;
    }

    public function isSuccessful(): bool
    {
        return self::STATUS_SUCCESS === $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'submittedAt' => $this->submittedAt->format(\DATE_ATOM),
            'trigger' => $this->trigger,
            'source' => $this->source,
            'host' => $this->host,
            'urlCount' => $this->urlCount,
            'status' => $this->status,
            'successfulEngines' => $this->successfulEngines,
            'failedEngines' => $this->failedEngines,
            'engines' => $this->engines,
        ];
    }
}
