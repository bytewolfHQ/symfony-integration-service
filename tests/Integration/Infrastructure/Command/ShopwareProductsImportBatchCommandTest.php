<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Command;

use App\Command\Integration\ShopwareProductsImportBatchCommand;
use App\Integration\Domain\ProductDraft;
use App\Integration\Infrastructure\Shopware\Product\Import\ProductCsvImportRunnerInterface;
use App\Integration\Infrastructure\Shopware\Product\Import\ProductCsvReaderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ShopwareProductsImportBatchCommandTest extends TestCase
{
    private function makeTester(
        ProductCsvReaderInterface $csvReader,
        ProductCsvImportRunnerInterface $runner,
    ): CommandTester {
        return new CommandTester(
            new ShopwareProductsImportBatchCommand($csvReader, $runner)
        );
    }

    public function test_processes_files_and_shows_summary(): void
    {
        $tmpDir = sys_get_temp_dir() . '/batch_test_' . uniqid();
        mkdir($tmpDir);
        $csvFile = $tmpDir . '/products.csv';
        file_put_contents($csvFile, "productNumber,name\nP-001,Test Product\n");

        $draft = new ProductDraft('P-001', 'Test Product');

        $csvReader = $this->createStub(ProductCsvReaderInterface::class);
        $csvReader->method('read')->willReturn([$draft]);

        $runner = $this->createStub(ProductCsvImportRunnerInterface::class);
        $runner->method('importDrafts')->willReturn([
            'total' => 1, 'created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
            'results' => [['productNumber' => 'P-001', 'name' => 'Test Product', 'action' => 'create']],
        ]);

        $processedDir = $tmpDir . '/processed';
        $failedDir = $tmpDir . '/failed';

        $tester = $this->makeTester($csvReader, $runner);
        $tester->execute([
            '--dir' => $tmpDir,
            '--pattern' => 'products.csv',
            '--processed-dir' => $processedDir,
            '--failed-dir' => $failedDir,
        ]);

        self::assertStringContainsString('created=1', $tester->getDisplay());
        self::assertSame(0, $tester->getStatusCode());

        // Cleanup
        array_map('unlink', glob($processedDir . '/*') ?: []);
        array_map('unlink', glob($failedDir . '/*') ?: []);
        @rmdir($processedDir);
        @rmdir($failedDir);
        @rmdir($tmpDir);
    }

    public function test_dry_run_calls_importDrafts_with_dryRun_true(): void
    {
        $tmpDir = sys_get_temp_dir() . '/batch_dryrun_' . uniqid();
        mkdir($tmpDir);
        $csvFile = $tmpDir . '/products.csv';
        file_put_contents($csvFile, "productNumber,name\nP-001,Test Product\n");

        $draft = new ProductDraft('P-001', 'Test Product');

        $csvReader = $this->createStub(ProductCsvReaderInterface::class);
        $csvReader->method('read')->willReturn([$draft]);

        // Use createMock here — we need to assert $dryRun=true is passed
        $runner = $this->createMock(ProductCsvImportRunnerInterface::class);
        $runner
            ->expects(self::once())
            ->method('importDrafts')
            ->with([$draft], true)
            ->willReturn([
                'total' => 1, 'created' => 0, 'updated' => 0, 'skipped' => 1, 'failed' => 0,
                'results' => [['productNumber' => 'P-001', 'name' => 'Test Product', 'action' => 'skipped']],
            ]);

        $tester = $this->makeTester($csvReader, $runner);
        $tester->execute([
            '--dir' => $tmpDir,
            '--pattern' => 'products.csv',
            '--processed-dir' => $tmpDir . '/processed',
            '--failed-dir' => $tmpDir . '/failed',
            '--dry-run' => true,
        ]);

        // File must NOT be moved on dry-run
        self::assertFileExists($csvFile);
        self::assertStringContainsString('dry-run', $tester->getDisplay());

        // Cleanup
        @unlink($csvFile);
        @rmdir($tmpDir);
    }

    public function test_no_matching_files_returns_success(): void
    {
        $tmpDir = sys_get_temp_dir() . '/batch_empty_' . uniqid();
        mkdir($tmpDir);

        $tester = $this->makeTester(
            $this->createStub(ProductCsvReaderInterface::class),
            $this->createStub(ProductCsvImportRunnerInterface::class),
        );
        $tester->execute([
            '--dir' => $tmpDir,
            '--pattern' => 'products*.csv',
            '--processed-dir' => $tmpDir . '/processed',
            '--failed-dir' => $tmpDir . '/failed',
        ]);

        self::assertStringContainsString('No matching files found', $tester->getDisplay());
        self::assertSame(0, $tester->getStatusCode());

        @rmdir($tmpDir);
    }

    public function test_missing_directory_returns_failure(): void
    {
        $tester = $this->makeTester(
            $this->createStub(ProductCsvReaderInterface::class),
            $this->createStub(ProductCsvImportRunnerInterface::class),
        );
        $tester->execute([
            '--dir' => '/nonexistent/path/xyz',
            '--processed-dir' => '/tmp/processed',
            '--failed-dir' => '/tmp/failed',
        ]);

        self::assertSame(1, $tester->getStatusCode());
    }
}
