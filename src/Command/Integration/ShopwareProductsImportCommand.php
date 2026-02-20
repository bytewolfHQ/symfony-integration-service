<?php

declare(strict_types=1);

namespace App\Command\Integration;

use App\Integration\Infrastructure\Shopware\Product\ProductDraft;
use App\Integration\Infrastructure\Shopware\Product\ShopwareProductImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'integration:shopware:products:import',
    description: 'Import products into Shopware (hardcoded draft list; upsert by productNumber).'
)]
final class ShopwareProductsImportCommand extends Command
{
    public function __construct(
        private readonly ShopwareProductImportService $importService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not change anything, only show what would happen.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        $drafts = [
            new ProductDraft('IMP-001', 'Imported product 001'),
            new ProductDraft('IMP-002', 'Imported product 002'),
            new ProductDraft('IMP-003', 'Imported product 003'),
        ];

        $output->writeln(sprintf('Importing %d products%s', count($drafts), $dryRun ? ' (dry-run)' : ''));

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
                    'error: %s | %s (%s)',
                    $draft->productNumber,
                    $draft->name, $e->getMessage()
                ));
            }
        }

        $output->writeln(sprintf(
            'Summary: created=%d, updated=%d, failed=%d%s',
            $created,
            $updated,
            $failed,
            $dryRun ? ' (dry-run)' : ''
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
