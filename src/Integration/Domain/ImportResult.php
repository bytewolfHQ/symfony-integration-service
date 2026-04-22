<?php

declare(strict_types=1);

namespace App\Integration\Domain;

final readonly class ImportResult
{
    /**
     * @param list<array{productNumber: string, name: string, action: string}> $results
     */
    public function __construct(
        public int $total,
        public int $created,
        public int $updated,
        public int $skipped,
        public int $failed,
        public array $results,
        public bool $dryRun = false,
    ) {
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    public function summary(): string
    {
        return sprintf(
            'Summary: created=%d, updated=%d, skipped=%d, failed=%d%s',
            $this->created,
            $this->updated,
            $this->skipped,
            $this->failed,
            $this->dryRun ? ' (dry-run)' : '',
        );
    }
}
