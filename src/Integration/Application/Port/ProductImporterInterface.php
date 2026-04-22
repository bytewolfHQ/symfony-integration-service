<?php

declare(strict_types=1);

namespace App\Integration\Application\Port;

use App\Integration\Domain\ImportResult;
use App\Integration\Domain\ProductDraft;

interface ProductImporterInterface
{
    /**
     * Imports a list of product drafts and returns the result.
     *
     * @param list<ProductDraft> $drafts
     */
    public function import(array $drafts, bool $dryRun = false): ImportResult;
}