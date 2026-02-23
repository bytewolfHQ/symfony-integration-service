<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Infrastructure\Shopware\Product\Import\ProductCsvReader;
use PHPUnit\Framework\TestCase;

final class ProductCsvReaderTest extends TestCase
{
    public function test_reads_drafts_from_csv(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv_');
        self::assertNotFalse($tmp);

        file_put_contents($tmp, "productNumber,name\nIMP-201,Name 201\nIMP-202,Name 202\n");

        $reader = new ProductCsvReader();
        $drafts = $reader->read($tmp);

        self::assertCount(2, $drafts);
        self::assertSame('IMP-201', $drafts[0]->productNumber);
        self::assertSame('Name 201', $drafts[0]->name);

        @unlink($tmp);
    }

    public function test_skips_invalid_rows(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv_');
        self::assertNotFalse($tmp);

        file_put_contents($tmp, "productNumber,name\n,Missing number\nIMP-301,\nIMP-302,Ok\n");

        $reader = new ProductCsvReader();
        $drafts = $reader->read($tmp);

        self::assertCount(1, $drafts);
        self::assertSame('IMP-302', $drafts[0]->productNumber);

        @unlink($tmp);
    }
}