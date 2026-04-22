<?php

declare(strict_types=1);

namespace App\Integration\Application\Port;

use App\Integration\Domain\ProductDraft;

interface ProductReaderInterface
{
    /**
     * Reads product data from a source and returns a list of drafts.
     *
     * @return list<ProductDraft>
     */
    public function read(string $source, string $delimiter = ',', ?int $limit = null): array;
}