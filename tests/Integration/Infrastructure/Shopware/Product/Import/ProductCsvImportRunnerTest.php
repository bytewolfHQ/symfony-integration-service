<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Infrastructure\Shopware\Product\Import\ProductCsvImportRunner;
use App\Integration\Infrastructure\Shopware\Product\Import\ProductDraftValidator;
use App\Integration\Infrastructure\Shopware\Product\Import\ProductDraftValidatorInterface;
use App\Integration\Domain\ProductDraft;
use App\Integration\Domain\ValidationError;
use App\Integration\Infrastructure\Shopware\Product\ShopwareProductImportInterface;
use PHPUnit\Framework\TestCase;

final class ProductCsvImportRunnerTest extends TestCase
{
    public function test_import_drafts_counts_create_and_update(): void
    {
        $service = $this->createMock(ShopwareProductImportInterface::class);

        $service
            ->expects(self::exactly(2))
            ->method('upsert')
            ->willReturnOnConsecutiveCalls('create', 'update');

        $runner = new ProductCsvImportRunner($service, new ProductDraftValidator());

        $drafts = [
            new ProductDraft('IMP-401', 'Imported product 401'),
            new ProductDraft('IMP-402', 'Imported product 402'),
        ];

        $summary = $runner->importDrafts($drafts, false);

        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['created']);
        self::assertSame(1, $summary['updated']);
        self::assertSame(0, $summary['failed']);
        self::assertCount(2, $summary['results']);

        self::assertSame('create', $summary['results'][0]['action']);
        self::assertSame('IMP-401', $summary['results'][0]['productNumber']);

        self::assertSame('update', $summary['results'][1]['action']);
        self::assertSame('IMP-402', $summary['results'][1]['productNumber']);
    }

    public function test_import_drafts_counts_failures(): void
    {
        $service = $this->createMock(ShopwareProductImportInterface::class);

        $service
            ->expects(self::exactly(2))
            ->method('upsert')
            ->willReturnCallback(
                static function (ProductDraft $draft): string {
                    if ($draft->productNumber === 'IMP-501') {
                        throw new \RuntimeException('Broken row');
                    }

                    return 'create';
                }
            );

        $runner = new ProductCsvImportRunner($service, new ProductDraftValidator());

        $drafts = [
            new ProductDraft('IMP-501', 'Imported product 501'),
            new ProductDraft('IMP-502', 'Imported product 502'),
        ];

        $summary = $runner->importDrafts($drafts, false);

        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['created']);
        self::assertSame(0, $summary['updated']);
        self::assertSame(1, $summary['failed']);
        self::assertCount(2, $summary['results']);

        self::assertStringStartsWith('error:', $summary['results'][0]['action']);
        self::assertSame('create', $summary['results'][1]['action']);
    }

    public function test_validation_errors_are_counted_as_failed(): void
    {
        $service = $this->createStub(ShopwareProductImportInterface::class);
        $validator = $this->createStub(ProductDraftValidatorInterface::class);

        // First draft fails validation, second passes
        $validator
            ->method('validate')
            ->willReturnOnConsecutiveCalls(
                [new ValidationError(2, 'IMP-601', 'gross', 'required when net is provided')],
                [],
            );

        $service->method('upsert')->willReturn('create');

        $runner = new ProductCsvImportRunner($service, $validator);

        $drafts = [
            new ProductDraft('IMP-601', 'Product 601', null, null, null, null, 8.39),
            new ProductDraft('IMP-602', 'Product 602', null, null, 5, 9.99),
        ];

        $summary = $runner->importDrafts($drafts);

        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['created']);
        self::assertSame(0, $summary['updated']);
        self::assertSame(1, $summary['failed']);
    }

    public function test_validation_error_appears_in_results(): void
    {
        $service = $this->createStub(ShopwareProductImportInterface::class);
        $validator = $this->createStub(ProductDraftValidatorInterface::class);

        $validator
            ->method('validate')
            ->willReturn([new ValidationError(2, 'IMP-701', 'gross', 'required when net is provided')]);

        $runner = new ProductCsvImportRunner($service, $validator);

        $drafts = [new ProductDraft('IMP-701', 'Product 701', null, null, null, null, 8.39)];
        $summary = $runner->importDrafts($drafts);

        self::assertStringStartsWith('validation:', $summary['results'][0]['action']);
    }

    public function test_skipped_products_are_counted_separately(): void
    {
        $service = $this->createStub(ShopwareProductImportInterface::class);
        $validator = $this->createStub(ProductDraftValidatorInterface::class);

        $service->method('upsert')->willReturnOnConsecutiveCalls('update', 'skipped', 'skipped');
        $validator->method('validate')->willReturn([]);

        $runner = new ProductCsvImportRunner($service, $validator);

        $drafts = [
            new ProductDraft('IMP-101', 'Product 101'),
            new ProductDraft('IMP-102', 'Product 102'),
            new ProductDraft('IMP-103', 'Product 103'),
        ];

        $summary = $runner->importDrafts($drafts);

        self::assertSame(3, $summary['total']);
        self::assertSame(1, $summary['updated']);
        self::assertSame(2, $summary['skipped']);
        self::assertSame(0, $summary['failed']);
    }
}
