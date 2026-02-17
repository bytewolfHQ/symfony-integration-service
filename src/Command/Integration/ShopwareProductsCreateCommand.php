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
    name: 'integration:shopware:products:create',
    description: 'Create a shopware product via Shopware Admin API.',
)]
final class ShopwareProductsCreateCommand extends Command
{
    public function __construct(
        private readonly ShopwareAdminApiClient $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Product name', 'Testprodukt')
            ->addOption('number', null, InputOption::VALUE_REQUIRED, 'Product number', 'TEST-' . date('Ymd-His'))
            ->addOption('stock', null, InputOption::VALUE_REQUIRED, 'Stock', '10')
            ->addOption('gross', null, InputOption::VALUE_REQUIRED, 'Gross price', '19.99')
            ->addOption('net', null, InputOption::VALUE_REQUIRED, 'Net price', '16.80')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Currency ISO (default EUR)', 'EUR')
            ->addOption('taxRate', null,  InputOption::VALUE_REQUIRED, 'Tax rate (default 19)', '19');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name     = (string) $input->getOption('name');
        $number   = (string) $input->getOption('number');
        $stock    = max(0, (int) $input->getOption('stock'));
        $gross    = (float) $input->getOption('gross');
        $net      = (float) $input->getOption('net');
        $currency = strtoupper((string) $input->getOption('currency'));
        $taxRate  = (float) $input->getOption('taxRate');

        $currencyId = $this->resolveCurrencyId($currency);
        $taxId      = $this->resolveTaxId($taxRate);

        $payload = [
            'name' => $name,
            'productNumber' => $number,
            'stock' => $stock,
            'taxId' => $taxId,
            'price' => [[
                'currencyId' => $currencyId,
                'gross' => $gross,
                'net' => $net,
                'linked' => false,
            ]],
        ];

        $res = $this->client->requestOrFail(
            method: 'POST',
            path: '/api/product',
            json: $payload,
            authenticated: true
        );

        // On create, Shopware may return 204 No Content or a body depending on setup.
        $output->writeln('<info>Product created successfully.</info>');
        $output->writeln(sprintf('productNumber: %s', $number));
        $output->writeln(sprintf('currencyId: %s', $currencyId));
        $output->writeln(sprintf('taxId: %s', $taxId));
        $output->writeln(sprintf('status: %d', $res['status']));

        return Command::SUCCESS;
    }

    private function resolveCurrencyId(string $iso): string
    {
        $criteria = [
            'limit' => 1,
            'includes' => [
                'currency' => ['id', 'isoCode'],
            ],
            'filter' => [[
                'type' => 'equals',
                'field' => 'isoCode',
                'value' => $iso,
            ]],
        ];

        $res = $this->client->requestOrFail(
            method: 'POST',
            path: '/api/search/currency',
            json: $criteria,
            authenticated: true
        );

        $data = json_decode($res['body'], true);
        $id = $data['data'][0]['id'] ?? null;

        if (!is_string($id) || $id === '') {
            throw new \RuntimeException("Could not resolve currencyId for isoCode=$iso");
        }

        return $id;
    }

    private function resolveTaxId(float $rate): string
    {
        $criteria = [
            'limit' => 1,
            'includes' => [
                'tax' => ['id', 'taxRate', 'name'],
            ],
            'filter' => [[
                'type' => 'equals',
                'field' => 'taxRate',
                'value' => $rate,
            ]],
        ];

        $res = $this->client->requestOrFail(
            method: 'POST',
            path: '/api/search/tax',
            json: $criteria,
            authenticated: true
        );

        $data = json_decode($res['body'], true);
        $id = $data['data'][0]['id'] ?? null;

        if (!is_string($id) || $id === '') {
            throw new \RuntimeException("Could not resolve taxId for taxRate=$rate");
        }

        return $id;
    }
}