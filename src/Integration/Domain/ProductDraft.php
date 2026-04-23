<?php

declare(strict_types=1);

namespace App\Integration\Domain;

final readonly class ProductDraft
{
    public function __construct(
        public string $productNumber,
        public string $name,
        public string $manufacturer,
        public string $description,
        public ?int $stock = null,
        public ?float $gross = null,
        public ?float $net = null,
        public ?float $taxRate = null,
        public ?string $currency = null,
        public ?bool $active = null,
    ) {}
}
