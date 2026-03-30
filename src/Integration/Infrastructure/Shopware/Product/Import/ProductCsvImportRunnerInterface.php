<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Domain\ProductDraft;

interface ProductCsvImportRunnerInterface
{
    /**
     * @param list<ProductDraft> $drafts
     * @return array{
     *   total: int,
     *   created: int,
     *   updated: int,
     *   skipped: int,
     *   failed: int,
     *   results: list<array{productNumber: string, name: string, action: string}>
     * }
     */
    public function importDrafts(array $drafts, bool $dryRun = false): array;
}
