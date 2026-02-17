<?php

declare(strict_types=1);

namespace App\Command\Integration;

use App\Integration\Infrastructure\Http\Shopware\ShopwareAdminApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'integration:shopware:products:list',
    description: 'List first products via Shopware Admin API (search criteria)',
)]
final class ShopwareProductsListCommand extends Command
{
    public function __construct(private readonly ShopwareAdminApiClient $client,)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of products to show', 5)
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $page = max(1, (int) $input->getOption('page'));

        $criteria = [
            'limit' => $limit,
            'page' => $page,
            'includes' => [
                'product' => ['id', 'productNumber', 'translated'],
            ],
        ];

        $res = $this->client->requestOrFail(
            'POST',
            '/api/search/product',
            $criteria,
            [],
            [],
            true
        );

        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            $output->writeln('<error>Invalid JSON response</error>');
            return Command::FAILURE;
        }

        $total = $data['total'] ?? ($data['meta']['total'] ?? null);
        $totalText = is_numeric($total) ? (string) (int) $total : 'n/a';
        $output->writeln(sprintf('total: %s', $totalText));
        $output->writeln(sprintf('limit: %d, page: %d', $limit, $page));

        $items = $data['data'] ?? [];
        if (!is_array($items) || $items === []) {
            $output->writeln('<comment>No products returned.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('sample:');
        foreach ($items as $item) {
            $id = $item['id'] ?? 'n/a';
            $pn = $item['attributes']['productNumber'] ?? 'n/a';

            $name = 'n/a';
            if (isset($item['attributes']['translated']) && is_array($item['attributes']['translated'])) {
                $name = (string) ($item['attributes']['translated']['name'] ?? 'n/a');
            }

            $output->writeln(sprintf('- %s | %s | %s', $id, $pn, $name));
        }

        return Command::SUCCESS;
    }
}