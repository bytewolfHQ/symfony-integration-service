<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Domain\ProductDraft;
use App\Integration\Domain\ValidationError;

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

        if ($draft->description !== null && mb_strlen($draft->description) > 65535) {
            $errors[] = new ValidationError($rowNumber, $draft->description, 'description','Too long (max 65535 chars)');
        }

        // manufacturer: optional, aber wenn gesetzt max. 255
        if ($draft->manufacturer !== null && mb_strlen($draft->manufacturer) > 255) {
            $errors[] = new ValidationError($rowNumber, $draft->manufacturer, 'manufacturer', 'Too long (max 255 chars)');
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
