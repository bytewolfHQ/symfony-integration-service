<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product\Import;

final readonly class ValidationError
{
    public function __construct(
        // CSV row number (header = 1, first data row = 2)
        public int $rowNumber,
        // productNumber from the row, or null if it could not be read
        public ?string $productNumber,
        // which field caused the error, e.g. "gross"
        public string $field,
        // human-readable reason, e.g. "required for price entry"
        public string $reason,
    ) {}

    public function toString(): string
    {
        $identifier = $this->productNumber ?? 'unknown';

        return sprintf(
            'Row %d [%s] - %s: %s',
            $this->rowNumber,
            $identifier,
            $this->field,
            $this->reason
        );
    }
}
