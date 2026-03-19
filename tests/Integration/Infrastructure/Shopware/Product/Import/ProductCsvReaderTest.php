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

    public function test_reads_extended_fields_from_csv(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv_');
        self::assertNotFalse($tmp);

        file_put_contents($tmp, implode("\n", [
            'productNumber,name,stock,gross,net,taxRate,currency,active',
            'IMP-201,Product 201,10,19.99,16.80,19,EUR,true',
            'IMP-202,Product 202,0,29.99,25.20,19,EUR,false',
        ]));

        $reader = new ProductCsvReader();
        $drafts = $reader->read($tmp);

        self::assertCount(2, $drafts);

        self::assertSame(10, $drafts[0]->stock);
        self::assertSame(19.99, $drafts[0]->gross);
        self::assertSame(16.80, $drafts[0]->net);
        self::assertSame(19.0, $drafts[0]->taxRate);
        self::assertSame('EUR', $drafts[0]->currency);
        self::assertTrue($drafts[0]->active);

        self::assertFalse($drafts[1]->active);
        self::assertSame(0, $drafts[1]->stock);

        @unlink($tmp);
    }

    public function test_optional_fields_default_to_null(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv_');
        self::assertNotFalse($tmp);

        // taxRate, currency and active are empty
        file_put_contents($tmp, implode("\n", [
            'productNumber,name,stock,gross,net,taxRate,currency,active',
            'IMP-301,Product 301,5,9.99,,,, ',
        ]));

        $reader = new ProductCsvReader();
        $drafts = $reader->read($tmp);

        self::assertCount(1, $drafts);
        self::assertNull($drafts[0]->taxRate);
        self::assertNull($drafts[0]->currency);
        self::assertNull($drafts[0]->active);

        @unlink($tmp);
    }

    public function test_invalid_values_become_null(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv_');
        self::assertNotFalse($tmp);

        file_put_contents($tmp, implode("\n", [
            'productNumber,name,stock,gross,net,taxRate,currency,active',
            'IMP-401,Product 401,notanumber,alsonotanumber,,,,maybe',
        ]));

        $reader = new ProductCsvReader();
        $drafts = $reader->read($tmp);

        self::assertCount(1, $drafts);
        self::assertNull($drafts[0]->stock);   // "notanumber" → null
        self::assertNull($drafts[0]->gross);   // "alsonotanumber" → null
        self::assertNull($drafts[0]->active);  // "maybe" → null (not in bool map)

        @unlink($tmp);
    }
}