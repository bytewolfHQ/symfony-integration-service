<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product;

use App\Integration\Domain\ProductDraft;

interface ShopwareProductImportInterface
{
    /**
     * Return "create" or "update"
     */
    public function upsert(ProductDraft $draft, bool $dryRun = false): string;
}
