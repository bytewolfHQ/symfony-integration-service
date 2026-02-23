<?php

declare(strict_types=1);

namespace App\Command\Integration;

use App\Integration\Infrastructure\Shopware\Product\Import\ProductCsvReader;
use App\Integration\Infrastructure\Shopware\Product\ShopwareProductImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'integration:shopware:products:import-csv',
    description: 'Import products into Shopware from a CSV file (minimal schema: productNumber,name).',
)]
final class ShopwareProductsImportCsvCommand extends Command
{
    public function __construct(
        private readonly ProductCsvReader $csvReader,
        private readonly ShopwareProductImportService $importService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to CSV file (required).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not change anything, only show what would happen.')
            ->addOption('delimiter', null, InputOption::VALUE_REQUIRED, 'CSV delimiter (default: ,).', ',')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Optional max number of rows to import.', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getOption('file');
        if ($file === '') {
            $output->writeln('<error>Missing required option: --file</error>');
            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $delimiter = (string) $input->getOption('delimiter');
        $limitOpt = $input->getOption('limit');
        $limit = $limitOpt === null ? null : (int) $limitOpt;

        try {
            $drafts = $this->csvReader->read($file, $delimiter, $limit);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Importing %d products from CSV%s',
            count($drafts),
            $dryRun ? ' (dry-run)' : ''
        ));

        if ($drafts === []) {
            $output->writeln('<comment>No valid rows found.</comment>');
            return Command::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        $failed = 0;

        foreach ($drafts as $draft) {
            try {
                $action = $this->importService->upsert($draft, $dryRun);

                match ($action) {
                    'create' => $created++,
                    'update' => $updated++,
                    default => null,
                };

                $output->writeln(sprintf('%s: %s | %s', $action, $draft->productNumber, $draft->name));
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln(sprintf(
                    '<error>error: %s | %s (%s)</error>',
                    $draft->productNumber,
                    $draft->name,
                    $e->getMessage()
                ));
            }
        }

        $output->writeln(sprintf(
            '<info>Summary: created=%d, updated=%d, failed=%d%s</info>',
            $created,
            $updated,
            $failed,
            $dryRun ? ' (dry-run)' : ''
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}