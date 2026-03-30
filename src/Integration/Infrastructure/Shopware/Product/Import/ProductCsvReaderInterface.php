<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Domain\ProductDraft;

interface ProductCsvReaderInterface
{
    /**
     * @return list<ProductDraft>
     */
    public function read(string $file, string $delimiter = ',', ?int $limit = null): array;
}
