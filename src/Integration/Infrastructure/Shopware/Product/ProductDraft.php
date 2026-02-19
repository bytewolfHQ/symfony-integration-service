<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product;

final readonly class ProductDraft
{
    public function __construct(
        public string $productNumber,
        public string $name,
    ) {}
}
