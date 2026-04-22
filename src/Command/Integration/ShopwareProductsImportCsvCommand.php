<?php

declare(strict_types=1);

namespace App\Command\Integration;

use App\Integration\Application\ImportProductsUseCase;
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
        private readonly ImportProductsUseCase $useCase,
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
            $result = $this->useCase->execute($file, $delimiter, $limit, $dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Importing %d products from CSV%s',
            $result->total,
            $dryRun ? ' (dry-run)' : ''
        ));

        if ($result->total === 0) {
            $output->writeln('<comment>No valid rows found.</comment>');
            return Command::SUCCESS;
        }

        foreach ($result->results as $r) {
            $output->writeln(sprintf('%s: %s | %s', $r['action'], $r['productNumber'], $r['name']));
        }

        $output->writeln($result->summary());

        return $result->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }
}