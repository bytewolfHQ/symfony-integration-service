<?php

declare(strict_types=1);

namespace App\Command\Integration;

use App\Integration\Infrastructure\Shopware\Product\Import\ProductCsvImportRunner;
use App\Integration\Infrastructure\Shopware\Product\Import\ProductCsvReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'integration:shopware:products:import-batch',
    description: 'Batch import product CSV files from a folder into Shopware (strict: move to processed/failed).'
)]
final class ShopwareProductsImportBatchCommand extends Command
{
    public function __construct(
        private readonly ProductCsvReader $csvReader,
        private readonly ProductCsvImportRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Incoming directory (required).', 'var/import/incoming')
            ->addOption('pattern', null, InputOption::VALUE_REQUIRED, 'File pattern (glob)', 'products*.csv')
            ->addOption('processed-dir', null, InputOption::VALUE_REQUIRED, 'Processed archive directory', 'var/import/processed')
            ->addOption('failed-dir', null, InputOption::VALUE_REQUIRED, 'Failed archive directory', 'var/import/failed')
            ->addOption('delimiter', null, InputOption::VALUE_REQUIRED, 'CSV delimiter (default: ,).', ',')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Optional max number of rows per file.', null)
            ->addOption('limit-files', null, InputOption::VALUE_REQUIRED, 'Optional max number of files to process.', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not change anything (no API calls, no file moves).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = rtrim((string) $input->getOption('dir'), '/');
        $pattern = (string) $input->getOption('pattern');
        $processedDir = rtrim((string) $input->getOption('processed-dir'), '/');
        $failedDir = rtrim((string) $input->getOption('failed-dir'), '/');
        $delimiter = (string) $input->getOption('delimiter');
        $limitOpt = $input->getOption('limit');
        $limit = $limitOpt === null ? null : (int) $limitOpt;
        $limitFilesOpt = $input->getOption('limit-files');
        $limitFiles = $limitFilesOpt === null ? null : (int) $limitFilesOpt;
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_dir($dir)) {
            $output->writeln('<error>Incoming directory not found: '.$dir.'</error>');
            return Command::FAILURE;
        }

        // Ensure archive dirs exist
        if (!$this->ensureDir($processedDir) || !$this->ensureDir($failedDir)) {
            $output->writeln('<error>Could not create processed/failed directories.</error>');
            return Command::FAILURE;
        }

        $files = glob($dir.'/'.$pattern) ?: [];
        sort($files);

        if ($limitFiles !== null) {
            $files = array_slice($files, 0, max(0, $limitFiles));
        }

        $output->writeln(sprintf(
            'Batch import: %d file(s)%s',
            count($files),
            $dryRun ? ' (dry-run)' : ''
        ));

        if ($files === []) {
            $output->writeln('<comment>No matching files found.</comment>');
            return Command::SUCCESS;
        }

        $overallFailedFiles = 0;

        foreach ($files as $file) {
            $basename = basename($file);
            $output->writeln('');
            $output->writeln('File: '.$basename);

            if ($dryRun) {
                $output->writeln('would read: '.$file);
                $output->writeln('would move to: processed/failed depending on result');
                continue;
            }

            try {
                $drafts = $this->csvReader->read($file, $delimiter, $limit);
                $summary = $this->runner->importDrafts($drafts, false);

                foreach ($summary['results'] as $r) {
                    $output->writeln(sprintf('%s: %s | %s', $r['action'], $r['productNumber'], $r['name']));
                }

                $output->writeln(sprintf(
                    'Summary: total=%d, created=%d, updated=%d, failed=%d',
                    $summary['total'],
                    $summary['created'],
                    $summary['updated'],
                    $summary['failed']
                ));

                $targetDir = ($summary['failed'] > 0) ? $failedDir : $processedDir;
                if ($summary['failed'] > 0) {
                    $overallFailedFiles++;
                }

                $targetPath = $this->uniqueTargetPath($targetDir, $basename);

                if (!@rename($file, $targetPath)) {
                    $output->writeln('<error>Could not move file to: '.$targetPath.'</error>');
                    $overallFailedFiles++;
                } else {
                    $output->writeln('moved to: '.$targetPath);
                }
            } catch (\Throwable $e) {
                $overallFailedFiles++;

                $output->writeln('<error>File failed: '.$e->getMessage().'</error>');

                $targetPath = $this->uniqueTargetPath($failedDir, $basename);
                if (!@rename($file, $targetPath)) {
                    $output->writeln('<error>Could not move failed file to: '.$targetPath.'</error>');
                } else {
                    $output->writeln('moved to: '.$targetPath);
                }
            }
        }

        return $overallFailedFiles > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        return @mkdir($dir, 0775, true) || is_dir($dir);
    }

    private function uniqueTargetPath(string $dir, string $basename): string
    {
        $path = $dir.'/'.$basename;
        if (!file_exists($path)) {
            return $path;
        }

        $ts = date('Ymd_His');
        $dot = strrpos($basename, '.');
        if ($dot === false) {
            return $dir.'/'.$basename.'_'.$ts;
        }

        $name = substr($basename, 0, $dot);
        $ext = substr($basename, $dot);

        return $dir.'/'.$name.'_'.$ts.$ext;
    }
}