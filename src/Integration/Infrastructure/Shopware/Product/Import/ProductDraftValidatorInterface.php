<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Domain\ProductDraft;
use App\Integration\Domain\ValidationError;

interface ProductDraftValidatorInterface
{
    /**
     * @return list<ValidationError>
     */
    public function validate(ProductDraft $draft, int $rowNumber): array;
}