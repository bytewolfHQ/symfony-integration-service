<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Infrastructure\Shopware\Product\ProductDraft;

final class ProductDraftValidator implements ProductDraftValidatorInterface
{
    /**
     * Validates a single ProductDraft and returns all validation errors.
     * Returns an empty array if the draft is valid.
     *
     * @return list<ValidationError>
     */
    public function validate(ProductDraft $draft, int $rowNumber): array
    {
        $errors = [];

        // productNumber and name are already enforced in the reader (empty rows
        // are skipped), but we guard here as well for direct API usage
        if ($draft->productNumber === '') {
            $errors[] = new ValidationError($rowNumber, null, 'productNumber', 'required');
        }

        if ($draft->name === '') {
            $errors[] = new ValidationError($rowNumber, $draft->productNumber, 'name', 'required');
        }

        // stock must be non-negative if provided
        if ($draft->stock !== null && $draft->stock < 0) {
            $errors[] = new ValidationError(
                $rowNumber,
                $draft->productNumber,
                'stock',
                'must be non-negative integer'
            );
        }

        // gross is required for a price entry — a row without gross will import
        // without any price, which is likely unintentional
        if ($draft->gross === null && $draft->net !== null) {
            $errors[] = new ValidationError(
                $rowNumber,
                $draft->productNumber,
                'gross',
                'required when net is provided'
            );
        }

        // taxRate must be positive if provided
        if ($draft->taxRate !== null && $draft->taxRate <= 0) {
            $errors[] = new ValidationError(
                $rowNumber,
                $draft->productNumber,
                'taxRate',
                'must be a positive number'
            );
        }

        return $errors;
    }
}
