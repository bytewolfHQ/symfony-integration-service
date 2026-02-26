<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Infrastructure\Shopware\Product\Import\ProductCsvImportRunner;
use App\Integration\Infrastructure\Shopware\Product\ProductDraft;
use App\Integration\Infrastructure\Shopware\Product\ShopwareProductImporterInterface;
use PHPUnit\Framework\TestCase;

final class ProductCsvImportRunnerTest extends TestCase
{
    public function test_import_drafts_counts_create_and_update(): void
    {
        $service = $this->createMock(ShopwareProductImporterInterface::class);

        $service
            ->expects(self::exactly(2))
            ->method('upsert')
            ->willReturnOnConsecutiveCalls('create', 'update');

        $runner = new ProductCsvImportRunner($service);

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
        $service = $this->createMock(ShopwareProductImporterInterface::class);

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

        $runner = new ProductCsvImportRunner($service);

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
}
