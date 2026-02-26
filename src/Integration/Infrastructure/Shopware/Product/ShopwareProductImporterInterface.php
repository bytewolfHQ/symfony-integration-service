<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product;

interface ShopwareProductImporterInterface
{
    /**
     * Return "create" or "update"
     */
    public function upsert(ProductDraft $draft, bool $dryRun = false): string;
}
