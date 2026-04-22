<?php

declare(strict_types=1);

namespace App\Integration\Application;

use App\Integration\Application\Port\ProductImporterInterface;
use App\Integration\Application\Port\ProductReaderInterface;
use App\Integration\Domain\ImportResult;

final class ImportProductsUseCase
{
    public function __construct(
        private readonly ProductReaderInterface $reader,
        private readonly ProductImporterInterface $importer,
    ) {}

    /**
     * Reads products from a source and imports them.
     * Returns an ImportResult with counts and per-row details.
     */
    public function execute(
        string $source,
        string $delimiter = ',',
        ?int $limit = null,
        bool $dryRun = false,
    ): ImportResult {
        $drafts = $this->reader->read($source, $delimiter, $limit);

        if ($drafts === []) {
            return new ImportResult(
                total: 0,
                created: 0,
                updated: 0,
                skipped: 0,
                failed: 0,
                results: [],
                dryRun: $dryRun,
            );
        }

        return $this->importer->import($drafts, $dryRun);
    }
}