<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Infrastructure\Shopware\Product\Import\ProductDraftValidator;
use App\Integration\Domain\ProductDraft;
use PHPUnit\Framework\TestCase;

final class ProductDraftValidatorTest extends TestCase
{
    private ProductDraftValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ProductDraftValidator();
    }

    public function test_valid_draft_returns_no_errors(): void
    {
        $draft = new ProductDraft('IMP-101', 'Product 101', null, null, 10, 19.99, null, 19.0, 'EUR', true);
        $errors = $this->validator->validate($draft, 2);

        self::assertCount(0, $errors);
    }

    public function test_negative_stock_returns_error(): void
    {
        $draft = new ProductDraft('IMP-101', 'Product 101', null, null, -1, 19.99);
        $errors = $this->validator->validate($draft, 2);

        self::assertCount(1, $errors);
        self::assertSame('stock', $errors[0]->field);
        self::assertSame(2, $errors[0]->rowNumber);
        self::assertSame('IMP-101', $errors[0]->productNumber);
    }

    public function test_net_without_gross_returns_error(): void
    {
        // net provided but gross is null → price entry would be incomplete
        $draft = new ProductDraft('IMP-102', 'Product 102', null, null, null, null, 8.39);
        $errors = $this->validator->validate($draft, 3);

        self::assertCount(1, $errors);
        self::assertSame('gross', $errors[0]->field);
        self::assertSame(3, $errors[0]->rowNumber);
    }

    public function test_zero_tax_rate_returns_error(): void
    {
        $draft = new ProductDraft('IMP-103', 'Product 103', null, null, null, 9.99, null, 0.0);
        $errors = $this->validator->validate($draft, 4);

        self::assertCount(1, $errors);
        self::assertSame('taxRate', $errors[0]->field);
    }

    public function test_negative_tax_rate_returns_error(): void
    {
        $draft = new ProductDraft('IMP-104', 'Product 104', null, null, null, 9.99, null, -7.0);
        $errors = $this->validator->validate($draft, 5);

        self::assertCount(1, $errors);
        self::assertSame('taxRate', $errors[0]->field);
    }

    public function test_multiple_errors_are_collected(): void
    {
        // negative stock AND net without gross → two errors at once
        $draft = new ProductDraft('IMP-105', 'Product 105', null, null, -5, null, 8.39);
        $errors = $this->validator->validate($draft, 6);

        self::assertCount(2, $errors);

        $fields = array_map(fn($e) => $e->field, $errors);
        self::assertContains('stock', $fields);
        self::assertContains('gross', $fields);
    }

    public function test_null_optional_fields_are_valid(): void
    {
        // stock, gross, taxRate all null → valid, just no price entry
        $draft = new ProductDraft('IMP-106', 'Product 106');
        $errors = $this->validator->validate($draft, 7);

        self::assertCount(0, $errors);
    }

    public function test_error_to_string_format(): void
    {
        $draft = new ProductDraft('IMP-107', 'Product 107', null, null, -1);
        $errors = $this->validator->validate($draft, 2);

        self::assertCount(1, $errors);
        self::assertSame(
            'Row 2 [IMP-107] - stock: must be non-negative integer',
            $errors[0]->toString()
        );
    }
}